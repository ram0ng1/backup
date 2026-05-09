<?php

use Flarum\Database\Migration;
use Flarum\Group\Group;

return Migration::addPermissions([
    'backup.manage' => Group::ADMINISTRATOR_ID,
]);
