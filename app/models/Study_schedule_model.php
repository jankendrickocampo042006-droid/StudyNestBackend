<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Study_schedule_model extends Model
{
    protected $table = 'study_schedules';
    protected $fillable = ['user_id', 'title', 'schedule_date', 'schedule_time', 'reminder_note'];
}
