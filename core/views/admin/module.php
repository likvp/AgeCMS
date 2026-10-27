<?php
/**
 * An admin page of a module. The module's HTML is trusted code (signed) and is printed as it is; it must escape what it shows.
 * @var \AgeCMS\ModuleResponse $reply
 * @var string $moduleName
 */
defined('AGECMS_BOOTED') || exit;
?>
<div class="pagehead"><div class="grow"><h1><?= h($reply->title) ?></h1></div></div>
<div class="module-page" data-module="<?= h($moduleName) ?>"><?= $reply->body ?></div>
