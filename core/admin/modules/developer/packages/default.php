<?php
	namespace BigTree;
	
	$packages = Extension::allByType("package", "last_updated DESC", true);
?>
<div id="packages_table"></div>
<script>
	BigTreeTable({
		container: "#packages_table",
		title: "<?=Text::translate("Packages", true)?>",
		data: <?=JSON::encodeColumns($packages, array("id", "name"))?>,
		actions: {
			"edit": "<?=DEVELOPER_ROOT?>packages/edit/{id}/",
			"delete": function(id) {
				BigTreeDialog({
					title: "<?=Text::translate("Uninstall Package", true)?>",
					content: '<p class="confirm"><?=Text::translate("Are you sure you want to uninstall this package?<br /><br />Related components, including those that were added to this package will also <strong>completely deleted</strong> (including related files).")?></p>',
					icon: "delete",
					alternateSaveText: "<?=Text::translate("OK", true)?>",
					callback: function() {
						document.location.href = "<?=DEVELOPER_ROOT?>packages/delete/?id=" + id + "<?php CSRF::drawGETToken(); ?>";
					}
				});
			}
		},
		columns: {
			name: { title: "<?=Text::translate("Package Name", true)?>", largeFont: true, actionHook: "edit" }
		},
		searchable: true,
		sortable: true
	});
</script>