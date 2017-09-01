<?php
	namespace BigTree;
	
	/**
	 * @global bool $showing_updates
	 */
	
	include Router::getIncludePath("admin/modules/developer/upgrade/_update-list.php");

	if (!$showing_updates) {
?>
<div class="container">
	<section>
		<p><?=Text::translate("No updates are available.")?></p>
	</section>
	<footer>
		<a href="javascript:history.go(-1);" class="button white"><?=Text::translate("Return")?></a>
	</footer>
</div>
<?php
	}
