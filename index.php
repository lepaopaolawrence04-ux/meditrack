<?php
require __DIR__ . '/db.php';
$page = $_GET['page'] ?? 'dashboard';
$error = '';
$needsSetup = (int)query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 try {
  if (!hash_equals($_SESSION['csrf'], (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('The form expired. Refresh and try again.');
  $action = $_POST['action'] ?? '';
  if ($action === 'setup' && $needsSetup) {
   $name = input('name',100); $email = input('email',200); $password = input('password',200);
   if (!filter_var($email,FILTER_VALIDATE_EMAIL) || strlen($password)<8) throw new RuntimeException('Use a valid email and a password of at least 8 characters.');
   transaction(function() use($name,$email,$password) {
    if ((int)query('SELECT COUNT(*) FROM users')->fetchColumn() !== 0) throw new RuntimeException('Setup is already complete.');
    query('INSERT INTO users(name,email,password,role) VALUES (?,?,?,?)',[$name,strtolower($email),password_hash($password,PASSWORD_DEFAULT),'administrator']);
    logActivity('Initial administrator created');
   });
   redirect('login');
  }
  if ($action === 'login') {
   if (($_SESSION['login_after'] ?? 0) > time()) throw new RuntimeException('Please wait 30 seconds before trying again.');
   $account = query('SELECT * FROM users WHERE email=?',[strtolower(input('email',200))])->fetch(PDO::FETCH_ASSOC);
   if (!$account || !password_verify(input('password',200),$account['password'])) {
    $_SESSION['login_after'] = time()+30; throw new RuntimeException('Incorrect email or password.');
   }
   session_regenerate_id(true); $_SESSION['user_id']=$account['id']; unset($_SESSION['login_after']);
   logActivity('Logged in'); redirect('dashboard');
  }
  if (!$user) throw new RuntimeException('Please sign in first.');
  if ($action === 'logout') { logActivity('Logged out'); $_SESSION=[]; session_destroy(); redirect('login'); }
  $areas=['patient_save'=>'patients','patient_delete'=>'delete','appointment_save'=>'appointments','appointment_status'=>'appointments','record_save'=>'prescribe','user_add'=>'logs'];
  if (isset($areas[$action]) && !allowed($areas[$action])) throw new RuntimeException('Your role cannot perform this action.');
  if ($action === 'patient_save') {
   $id=(int)($_POST['id']??0); $name=input('name',100); $dob=input('dob',10); $sex=input('sex',20); $contact=input('contact',40); $address=input('address',500);
   if (!validDate($dob) || $dob>date('Y-m-d') || !in_array($sex,['Female','Male','Other'])) throw new RuntimeException('Check the birth date and sex.');
   // Transaction 1: patient registration/update plus activity log.
   transaction(function() use($id,$name,$dob,$sex,$contact,$address) {
    if ($id) {
     if (!query('SELECT id FROM patients WHERE id=?',[$id])->fetchColumn()) throw new RuntimeException('Patient not found.');
     query('UPDATE patients SET name=?,dob=?,sex=?,contact=?,address=? WHERE id=?',[$name,$dob,$sex,$contact,$address,$id]);
    } else { query('INSERT INTO patients(name,dob,sex,contact,address) VALUES (?,?,?,?,?)',[$name,$dob,$sex,$contact,$address]); $id=(int)$GLOBALS['db']->lastInsertId(); }
    logActivity('Saved patient #'.$id);
   }); redirect('patients');
  } elseif ($action === 'patient_delete') {
   transaction(function() { $id=(int)$_POST['id']; query('DELETE FROM patients WHERE id=?',[$id]); logActivity('Deleted patient #'.$id.' and associated appointments and medical records'); }); redirect('patients');
  } elseif ($action === 'appointment_save') {
   $patient=(int)$_POST['patient_id']; $doctor=(int)$_POST['doctor_id']; $when=input('scheduled_at',16); $reason=input('reason',500);
   $date=DateTimeImmutable::createFromFormat('!Y-m-d\TH:i',$when);
   if (!$date || $date->format('Y-m-d\TH:i')!==$when || $date < new DateTimeImmutable()) throw new RuntimeException('Choose a valid future appointment time.');
   if (!query("SELECT id FROM users WHERE id=? AND role='doctor'",[$doctor])->fetchColumn()) throw new RuntimeException('Choose a doctor.');
   // Transaction 2: booking and audit log; unique index prevents double booking.
   transaction(function() use($patient,$doctor,$when,$reason) { query('INSERT INTO appointments(patient_id,doctor_id,scheduled_at,reason) VALUES (?,?,?,?)',[$patient,$doctor,$when,$reason]); logActivity('Scheduled appointment for patient #'.$patient); }); redirect('appointments');
  } elseif ($action === 'appointment_status') {
   $status=input('status',20); if (!in_array($status,['Scheduled','Completed','Cancelled'])) throw new RuntimeException('Invalid status.');
   transaction(function() use($status) { query('UPDATE appointments SET status=? WHERE id=?',[$status,(int)$_POST['id']]); logActivity('Changed appointment #'.(int)$_POST['id'].' to '.$status); }); redirect('appointments');
  } elseif ($action === 'record_save') {
   $patient=(int)$_POST['patient_id']; $diagnosis=input('diagnosis',2000); $treatment=input('treatment',2000);
   $medicine=trim((string)($_POST['medicine']??'')); $dosage=''; $instructions=''; $quantity=0;
   if ($medicine!=='') { $medicine=input('medicine',200); $dosage=input('dosage',200); $instructions=input('instructions',1000); $quantity=filter_var($_POST['quantity']??'',FILTER_VALIDATE_INT); if (!$quantity || $quantity<1 || $quantity>10000) throw new RuntimeException('Quantity must be between 1 and 10000.'); }
   // Transaction 3: consultation, optional prescription and audit log are atomic.
   transaction(function() use($patient,$diagnosis,$treatment,$medicine,$dosage,$quantity,$instructions,$user) {
    query('INSERT INTO records(patient_id,doctor_id,diagnosis,treatment) VALUES (?,?,?,?)',[$patient,$user['id'],$diagnosis,$treatment]);
    $record=(int)$GLOBALS['db']->lastInsertId();
    if ($medicine!=='') query('INSERT INTO prescriptions(record_id,medicine,dosage,quantity,instructions) VALUES (?,?,?,?,?)',[$record,$medicine,$dosage,$quantity,$instructions]);
    logActivity('Added consultation #'.$record.' for patient #'.$patient);
   }); redirect('records');
  } elseif ($action === 'profile' || $action === 'user_add') {
   $name=input('name',100); $email=strtolower(input('email',200)); $password=trim((string)($_POST['password']??''));
   if (!filter_var($email,FILTER_VALIDATE_EMAIL) || ($password!=='' && (strlen($password)<8 || strlen($password)>200))) throw new RuntimeException('Use a valid email and a password of 8 to 200 characters.');
   transaction(function() use($action,$name,$email,$password,$user) {
    if ($action==='user_add') {
     $role=input('role',20); if (!in_array($role,['administrator','receptionist','nurse','doctor']) || $password==='') throw new RuntimeException('Choose a role and enter a password.');
     query('INSERT INTO users(name,email,password,role) VALUES (?,?,?,?)',[$name,$email,password_hash($password,PASSWORD_DEFAULT),$role]); logActivity('Created staff account: '.$email);
    } else {
     if (!password_verify(input('current_password',200),$user['password'])) throw new RuntimeException('Current password is incorrect.');
     query('UPDATE users SET name=?,email=? WHERE id=?',[$name,$email,$user['id']]);
     if ($password!=='') query('UPDATE users SET password=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$user['id']]);
     logActivity('Updated own profile');
    }
   }); redirect($action==='profile'?'profile':'users');
  } else { throw new RuntimeException('Unknown action.'); }
 } catch (PDOException $e) { $error='Unable to save. Check for a duplicate email, an occupied doctor time, or a missing patient.'; }
 catch (Throwable $e) { $error=$e->getMessage(); }
}
if ($needsSetup) $page='setup'; elseif (!$user) $page='login';
$pageAccess=['patients'=>'patients','appointments'=>'appointments','records'=>'records','prescriptions'=>'records','reports'=>'reports','logs'=>'logs','users'=>'logs'];
if (isset($pageAccess[$page]) && !allowed($pageAccess[$page])) { http_response_code(403); $page='denied'; }
$titles=['dashboard'=>'Dashboard','patients'=>'Patient Records','appointments'=>'Appointments','records'=>'Medical Records','prescriptions'=>'Prescriptions','reports'=>'Reports','profile'=>'My Profile','logs'=>'Activity Logs','users'=>'Staff Accounts','login'=>'Login','setup'=>'First-time Setup','denied'=>'Access Denied'];
if (!isset($titles[$page])) { http_response_code(404); $page='denied'; }
function patientOptions(): void { foreach(query('SELECT id,name FROM patients ORDER BY name') as $p) echo '<option value="'.h($p['id']).'">#'.h($p['id']).' — '.h($p['name']).'</option>'; }
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>MediTrack - <?=h($titles[$page])?></title><link rel="stylesheet" href="style.css"></head><body>
<header><h1>MediTrack</h1><p>Simple Clinic Management System</p></header>
<?php if($user): ?><nav><?php foreach(['dashboard','patients','appointments','records','prescriptions','reports','profile','users','logs'] as $link): if(isset($pageAccess[$link])&&!allowed($pageAccess[$link])) continue; ?><a class="<?=$page===$link?'active':''?>" href="?page=<?=$link?>"><?=h($titles[$link])?></a><?php endforeach; ?></nav><?php endif; ?>
<main><h2><?=h($titles[$page])?></h2><?php if($user): ?><p>Signed in as <?=h($user['name'])?> (<?=h($user['role'])?>)</p><?php endif; ?>
<?php if($error): ?><p class="error" role="alert"><?=h($error)?></p><?php endif; ?>
<?php if($page==='setup' || $page==='login'): ?>
<form method="post" class="panel narrow"><?php csrf(); ?><input type="hidden" name="action" value="<?=$page?>">
<?php if($page==='setup'): ?><p>Create your administrator account to get started.</p><label>Full name<input name="name" required maxlength="100"></label><?php endif; ?>
<label>Email<input type="email" name="email" required maxlength="200" autocomplete="username"></label><label>Password<input type="password" name="password" required <?=$page==='setup'?'minlength="8"':''?> maxlength="200" autocomplete="<?=$page==='setup'?'new-password':'current-password'?>"></label><button><?=$page==='setup'?'Create administrator':'Login'?></button></form>
<?php elseif($page==='dashboard'): ?>
<div class="stats"><?php foreach(['Patients'=>'SELECT COUNT(*) FROM patients','Scheduled Appointments'=>"SELECT COUNT(*) FROM appointments WHERE status='Scheduled'",'Consultations'=>'SELECT COUNT(*) FROM records'] as $label=>$sql): ?><div class="panel"><strong><?=h(query($sql)->fetchColumn())?></strong><br><?=h($label)?></div><?php endforeach; ?></div>
<h3>Upcoming appointments</h3><div class="table-wrap"><table><tr><th>Patient</th><th>Doctor</th><th>Date and time</th></tr><?php $rows=query("SELECT p.name,u.name doctor,a.scheduled_at FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN users u ON u.id=a.doctor_id WHERE a.status='Scheduled' AND a.scheduled_at>=? ORDER BY a.scheduled_at LIMIT 10",[date('Y-m-d\TH:i')])->fetchAll(); foreach($rows as $r): ?><tr><td><?=h($r['name'])?></td><td><?=h($r['doctor'])?></td><td><?=h(str_replace('T',' ',$r['scheduled_at']))?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="3">No upcoming appointments.</td></tr><?php endif; ?></table></div>
<?php elseif($page==='patients'): $edit=query('SELECT * FROM patients WHERE id=?',[(int)($_GET['edit']??0)])->fetch(PDO::FETCH_ASSOC) ?: []; ?>
<form method="get"><input type="hidden" name="page" value="patients"><label>Search by name, patient ID or contact<input name="q" value="<?=h($_GET['q']??'')?>"></label><button>Search</button> <a href="?page=patients">Clear</a></form>
<h3><?=$edit?'Edit patient #'.h($edit['id']):'Add patient'?></h3><form method="post" class="panel grid"><?php csrf(); ?><input type="hidden" name="action" value="patient_save"><input type="hidden" name="id" value="<?=h($edit['id']??0)?>">
<label>Full name<input name="name" required maxlength="100" value="<?=h($edit['name']??'')?>"></label><label>Date of birth<input type="date" name="dob" required max="<?=date('Y-m-d')?>" value="<?=h($edit['dob']??'')?>"></label><label>Sex<select name="sex"><?php foreach(['Female','Male','Other'] as $s): ?><option <?=($edit['sex']??'')===$s?'selected':''?>><?=$s?></option><?php endforeach; ?></select></label><label>Contact number<input name="contact" required maxlength="40" value="<?=h($edit['contact']??'')?>"></label><label>Address<input name="address" required maxlength="500" value="<?=h($edit['address']??'')?>"></label><div><button>Save patient</button> <a href="?page=patients">Cancel edit</a></div></form>
<div class="table-wrap"><table><tr><th>ID</th><th>Name</th><th>Birth date</th><th>Sex</th><th>Contact</th><th>Address</th><th>Actions</th></tr><?php $q='%'.($_GET['q']??'').'%'; $rows=query('SELECT * FROM patients WHERE name LIKE ? OR CAST(id AS CHAR) LIKE ? OR contact LIKE ? ORDER BY id DESC',[$q,$q,$q])->fetchAll(); foreach($rows as $r): ?><tr><?php foreach(['id','name','dob','sex','contact','address'] as $key): ?><td><?=h($r[$key])?></td><?php endforeach; ?><td><a href="?page=patients&amp;edit=<?=$r['id']?>">Edit</a><?php if(allowed('delete')): ?><form method="post" onsubmit="return confirm('Delete this patient and all their appointments, consultations and prescriptions?')"><?php csrf(); ?><input type="hidden" name="action" value="patient_delete"><input type="hidden" name="id" value="<?=$r['id']?>"><button class="danger">Delete</button></form><?php endif; ?></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="7">No patients found.</td></tr><?php endif; ?></table></div>
<?php elseif($page==='appointments'): ?>
<form method="post" class="panel grid"><?php csrf(); ?><input type="hidden" name="action" value="appointment_save"><label>Patient<select name="patient_id" required><option value="">Select patient</option><?php patientOptions(); ?></select></label><label>Doctor<select name="doctor_id" required><option value="">Select doctor</option><?php foreach(query("SELECT id,name FROM users WHERE role='doctor' ORDER BY name") as $d): ?><option value="<?=$d['id']?>"><?=h($d['name'])?></option><?php endforeach; ?></select></label><label>Date and time<input type="datetime-local" name="scheduled_at" required></label><label>Reason<input name="reason" required maxlength="500"></label><div><button>Schedule appointment</button></div></form>
<p>An administrator can add doctors under Staff Accounts.</p><div class="table-wrap"><table><tr><th>ID</th><th>Patient</th><th>Doctor</th><th>Date and time</th><th>Reason</th><th>Status</th></tr><?php $rows=query('SELECT a.*,p.name patient,u.name doctor FROM appointments a JOIN patients p ON p.id=a.patient_id JOIN users u ON u.id=a.doctor_id ORDER BY scheduled_at DESC')->fetchAll(); foreach($rows as $r): ?><tr><?php foreach(['id','patient','doctor','scheduled_at','reason'] as $k): ?><td><?=h(str_replace('T',' ',$r[$k]))?></td><?php endforeach; ?><td><form method="post"><?php csrf(); ?><input type="hidden" name="action" value="appointment_status"><input type="hidden" name="id" value="<?=$r['id']?>"><select aria-label="Appointment status" name="status"><?php foreach(['Scheduled','Completed','Cancelled'] as $s): ?><option <?=$s===$r['status']?'selected':''?>><?=$s?></option><?php endforeach; ?></select><button>Update</button></form></td></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6">No appointments yet.</td></tr><?php endif; ?></table></div>
<?php elseif($page==='records'): ?>
<?php if(allowed('prescribe')): ?><h3>Add consultation</h3><form method="post" class="panel grid"><?php csrf(); ?><input type="hidden" name="action" value="record_save"><label>Patient<select name="patient_id" required><option value="">Select patient</option><?php patientOptions(); ?></select></label><label>Diagnosis<textarea name="diagnosis" required maxlength="2000"></textarea></label><label>Treatment<textarea name="treatment" required maxlength="2000"></textarea></label><label>Medicine (optional)<input name="medicine" maxlength="200"></label><label>Dosage<input name="dosage" maxlength="200" placeholder="e.g. 1 tablet twice daily"></label><label>Quantity<input type="number" name="quantity" min="1" max="10000"></label><label>Prescription instructions<textarea name="instructions" maxlength="1000"></textarea></label><div><button>Save consultation</button></div></form><?php endif; ?>
<div class="table-wrap"><table><tr><th>ID</th><th>Patient</th><th>Recorded by</th><th>Diagnosis</th><th>Treatment</th><th>Date (UTC)</th></tr><?php $rows=query('SELECT r.*,p.name patient,u.name doctor FROM records r JOIN patients p ON p.id=r.patient_id JOIN users u ON u.id=r.doctor_id ORDER BY r.id DESC')->fetchAll(); foreach($rows as $r): ?><tr><?php foreach(['id','patient','doctor','diagnosis','treatment','created_at'] as $k): ?><td><?=nl2br(h($r[$k]))?></td><?php endforeach; ?></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6">No consultations yet.</td></tr><?php endif; ?></table></div>
<?php elseif($page==='prescriptions'): ?>
<p>Prescriptions are added with a consultation under Medical Records.</p><div class="table-wrap"><table><tr><th>Patient</th><th>Medicine</th><th>Dosage</th><th>Quantity</th><th>Instructions</th><th>Date (UTC)</th></tr><?php $rows=query('SELECT p.name,s.medicine,s.dosage,s.quantity,s.instructions,r.created_at FROM prescriptions s JOIN records r ON r.id=s.record_id JOIN patients p ON p.id=r.patient_id ORDER BY s.id DESC')->fetchAll(); foreach($rows as $r): ?><tr><?php foreach($r as $v): ?><td><?=h($v)?></td><?php endforeach; ?></tr><?php endforeach; if(!$rows): ?><tr><td colspan="6">No prescriptions yet.</td></tr><?php endif; ?></table></div>
<?php elseif($page==='reports'): $from=$_GET['from']??date('Y-m-01'); $to=$_GET['to']??date('Y-m-d'); if(!validDate($from)||!validDate($to)||$from>$to) { $from=date('Y-m-01'); $to=date('Y-m-d'); } ?>
<form method="get" class="panel grid"><input type="hidden" name="page" value="reports"><label>From<input type="date" name="from" required value="<?=h($from)?>"></label><label>To<input type="date" name="to" required value="<?=h($to)?>"></label><div><button>Generate report</button> <button type="button" onclick="window.print()">Print / Save PDF</button></div></form>
<h3>Clinic report: <?=h($from)?> to <?=h($to)?></h3><p>New patients: <?=h(query('SELECT COUNT(*) FROM patients WHERE date(created_at) BETWEEN ? AND ?',[$from,$to])->fetchColumn())?></p><p>Consultations: <?=h(query('SELECT COUNT(*) FROM records WHERE date(created_at) BETWEEN ? AND ?',[$from,$to])->fetchColumn())?></p><table><tr><th>Appointment status</th><th>Total</th></tr><?php foreach(['Scheduled','Completed','Cancelled'] as $status): ?><tr><td><?=$status?></td><td><?=h(query('SELECT COUNT(*) FROM appointments WHERE substr(scheduled_at,1,10) BETWEEN ? AND ? AND status=?',[$from,$to,$status])->fetchColumn())?></td></tr><?php endforeach; ?></table>
<h3>Patients registered in this period</h3><table><tr><th>ID</th><th>Name</th><th>Registration date (UTC)</th></tr><?php foreach(query('SELECT id,name,created_at FROM patients WHERE date(created_at) BETWEEN ? AND ? ORDER BY id',[$from,$to]) as $r): ?><tr><td><?=h($r['id'])?></td><td><?=h($r['name'])?></td><td><?=h($r['created_at'])?></td></tr><?php endforeach; ?></table>
<?php elseif($page==='profile' || $page==='users'): ?>
<form method="post" class="panel narrow"><?php csrf(); ?><input type="hidden" name="action" value="<?=$page==='profile'?'profile':'user_add'?>"><label>Full name<input name="name" required maxlength="100" value="<?=h($page==='profile'?$user['name']:'')?>"></label><label>Email<input name="email" type="email" required maxlength="200" value="<?=h($page==='profile'?$user['email']:'')?>"></label>
<?php if($page==='profile'): ?><label>Current password<input type="password" name="current_password" required autocomplete="current-password"></label><?php else: ?><label>Role<select name="role"><?php foreach(['receptionist','nurse','doctor','administrator'] as $role): ?><option><?=$role?></option><?php endforeach; ?></select></label><?php endif; ?>
<label><?=$page==='profile'?'New password (leave blank to keep current)':'Password'?><input type="password" name="password" minlength="8" maxlength="200" autocomplete="new-password" <?=$page==='users'?'required':''?>></label><button><?=$page==='profile'?'Update profile':'Add staff account'?></button></form>
<?php if($page==='users'): ?><table><tr><th>Name</th><th>Email</th><th>Role</th></tr><?php foreach(query('SELECT name,email,role FROM users ORDER BY name') as $r): ?><tr><?php foreach($r as $v): ?><td><?=h($v)?></td><?php endforeach; ?></tr><?php endforeach; ?></table><?php endif; ?>
<?php elseif($page==='logs'): ?>
<p>Latest 300 activities. Dates are stored in UTC.</p><div class="table-wrap"><table><tr><th>Date</th><th>User</th><th>Activity</th></tr><?php foreach(query("SELECT l.created_at,COALESCE(u.name,'System') name,l.action FROM logs l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.id DESC LIMIT 300") as $r): ?><tr><td><?=h($r['created_at'])?></td><td><?=h($r['name'])?></td><td><?=h($r['action'])?></td></tr><?php endforeach; ?></table></div>
<?php else: ?><p>This page is unavailable for your account.</p><?php endif; ?>
<?php if($user): ?><form method="post" class="logout"><?php csrf(); ?><input type="hidden" name="action" value="logout"><button>Logout</button></form><?php endif; ?>
</main><footer>MediTrack &bull; Clinic Management Project</footer></body></html>

