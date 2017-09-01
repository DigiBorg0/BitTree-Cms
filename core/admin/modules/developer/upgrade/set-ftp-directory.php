<?php
	namespace BigTree;
	
	CSRF::verify();
	
	if (!Setting::exists("bigtree-internal-ftp-upgrade-root")) {
		$setting = new Setting;
		$setting->ID = "bigtree-internal-ftp-upgrade-root";
		$setting->System = "on";
	} else {
		$setting = new Setting("bigtree-internal-ftp-upgrade-root");
	}
	
	$setting->Value = $_POST["ftp_root"];
	$setting->save();

	Router::redirect(DEVELOPER_ROOT."upgrade/install/");
	