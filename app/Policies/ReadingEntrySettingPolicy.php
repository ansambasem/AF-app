<?php

namespace App\Policies;

/**
 * The reading schedule: each branch's admin sets their own branch's reading
 * day and entry window; applying the company's schedule to all branches
 * stays with the Super Admin.
 */
class ReadingEntrySettingPolicy extends BranchSchedulePolicy {}
