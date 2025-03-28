<?php
require_once DOL_DOCUMENT_ROOT.'/core/triggers/dolibarrtriggers.class.php';
class InterfaceSyncYunoHostTriggers extends DolibarrTriggers
{
	public function __construct($db)
	{
		$this->db = $db;
		$this->name = preg_replace('/^Interface/i', '', get_class($this));
		$this->family = "hr";
		$this->description = "SyncYunoHost triggers.";
		$this->version = '1.0.0';
		$this->picto = 'syncyunohost@syncyunohost';
	}
	public function getName()
	{
		return $this->name;
	}
	public function getDesc()
	{
		return $this->description;
	}
	public function runTrigger($action, $object, User $user, Translate $langs, Conf $conf)
	{
	    if (!isModEnabled('syncyunohost')) {
	        return 0; // Exit if the module is disabled
	    }

	    // Retrieve YunoHost configuration values
	    $yunohostBaseDomain = $conf->global->YUNOHOST_BASE_DOMAIN;
	    $yunohostMainGroup = $conf->global->YUNOHOST_MAIN_GROUP;
	    $yunohostOldUsers = 1;

	    // Handle actions using a switch statement
	    switch ($action) {
	        case 'MEMBER_CREATE':
	            $fullName = $this->getFullName($object);
		    $this->memberToUser($object->id);
	            $this->runCommand('create', $object->login, $object->pass, $fullName, $object->email, $yunohostBaseDomain);
	            break;

	        case 'MEMBER_SUBSCRIPTION_CREATE':
	            $this->handleSubscriptionCreate($object, $yunohostBaseDomain, $yunohostMainGroup, $yunohostOldUsers);
	            break;

	        case 'MEMBER_SUBSCRIPTION_DELETE':
	        case 'MEMBER_SUBSCRIPTION_EXPIRED': // custum trigger by Syncyunohost
	            $this->handleSubscriptionDelete($object, $yunohostBaseDomain, $yunohostMainGroup, $yunohostOldUsers);
	            break;

	        case 'MEMBER_VALIDATE':
	        case 'MEMBER_RESILIATE':
	        case 'MEMBER_NEW_PASSWORD':
	            if ($yunohostOldUsers) {
	                $fullName = $this->getFullName($object);
	                $newPass = $this->generateSecurePassword(20);
			$this->memberToUser($object->id);
	                $this->runCommand('create', $object->login, $newPass, $fullName, $object->email, $yunohostBaseDomain);
	            }
	            if ($action === 'MEMBER_NEW_PASSWORD') {
	                $this->runCommand('password', $object->login, $object->pass);
	            }
	            break;

	        case 'MEMBER_MODIFY':
	            $this->handleMemberModify($object, $yunohostOldUsers, $yunohostBaseDomain);
	            break;

	        case 'MEMBER_DELETE':
	            $this->runCommand('delete', $object->login);
	            break;

	        default:
	            // Log unmatched actions
	            // dol_syslog("No matching action for DebianSync trigger: $action", LOG_WARNING);
	            return 0;
	    }

	    return 0;
	}

	private function getFullName($object)
	{
	    // Generate full name based on company or personal name
	    return $object->company 
	        ? sprintf("%s", $object->company) 
	        : sprintf("%s %s", $object->firstname, $object->lastname);
	}

	private function handleSubscriptionCreate($object, $baseDomain, $mainGroup, $oldUsers)
	{
	    $member = new Adherent($this->db);
	    if ($member->fetch($object->fk_adherent) > 0) {
	        if ($oldUsers) {
	            $fullName = $this->getFullName($member);
	            $newPass = $this->generateSecurePassword(20);
	            $this->runCommand('create', $member->login, $newPass, $fullName, $member->email, $baseDomain);
	        }
	        $this->runCommand('activate', $member->login, $mainGroup);
	    }
	}

	private function handleSubscriptionDelete($object, $baseDomain, $mainGroup, $oldUsers)
	{
	    $member = new Adherent($this->db);
	    if ($member->fetch($object->fk_adherent) > 0) {
	        if ($oldUsers) {
	            $fullName = $this->getFullName($member);
	            $newPass = $this->generateSecurePassword(20);
	            $this->runCommand('create', $member->login, $newPass, $fullName, $member->email, $baseDomain);
	        }
	        $this->runCommand('deactivate', $member->login, $baseDomain, $mainGroup);
	    }
	}

	private function handleMemberModify($object, $oldUsers, $baseDomain)
	{
	    if ($oldUsers) {
	        $oldFullName = $this->getFullName($object->oldcopy);
	        $newPass = $this->generateSecurePassword(20);
	        $this->runCommand('create', $object->login, $newPass, $oldFullName, $object->oldcopy->email, $baseDomain);
	    }

	    $fullName = $this->getFullName($object);

	    // Update email if it has changed
	    if ($object->oldcopy->email !== $object->email) {
	        $this->runCommand('modify_email', $object->login, $object->email);
	    }

	    // Update full name if it has changed
	    if ($fullName !== $this->getFullName($object->oldcopy)) {
	        $this->runCommand('modify_fullname', $object->login, $fullName);
	    }

	    // Update password if provided
	    if ($object->pass) {
	        $this->runCommand('password', $object->login, $object->pass);
	    }
	}
	private function memberToUser($member_id){
		$found = 0;
		$sql = "SELECT COUNT(rowid) as nb FROM ".MAIN_DB_PREFIX."user WHERE fk_member = ".((int) $member_id);
		$resqlcount = $this->db->query($sql);
		if ($resqlcount) {
			$objcount = $this->db->fetch_object($resqlcount);
			if ($objcount) {
				$found = $objcount->nb;
			}
		}
		if (!$found) {
		    $member = new Adherent($this->db);
		    if ($member->fetch($member_id) > 0) {
				// Creation user
				$nuser = new User($this->db);
				$tmpuser = dol_clone($member, 0);
				$result = $nuser->create_from_member($tmpuser, $member->login);
			}
		}
	}
	private function generateSecurePassword($length = 12)
	{
	    $chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789!@#$%^&*()_+-=';
	    $password = '';
	    $charsLength = strlen($chars) - 1;

	    for ($i = 0; $i < $length; $i++) {
	        $password .= $chars[random_int(0, $charsLength)];
	    }

	    return $password;
	}

	private function runCommand($action, $username, $param1 = null, $param2 = null, $param3 = null, $param4 = null)
	{
	    // Sanitize arguments to prevent injection
	    $username = escapeshellarg($username);
	    $param1Arg = $param1 ? escapeshellarg($param1) : '';
	    $param2Arg = $param2 ? escapeshellarg($param2) : '';
	    $param3Arg = $param3 ? escapeshellarg($param3) : '';
	    $param4Arg = $param4 ? escapeshellarg($param4) : '';

	    // Construct the command
	    $cmd = "/usr/bin/sudo /usr/local/bin/syncyunohost.sh $action $username $param1Arg $param2Arg $param3Arg $param4Arg";

	    // Execute the command and return output
	    return shell_exec($cmd);
	}
}

