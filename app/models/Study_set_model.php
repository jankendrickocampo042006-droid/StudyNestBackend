<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Study_set_model extends Model
{
    protected $table = 'study_sets';
    protected $fillable = ['user_id', 'subject_id', 'topic_id', 'title', 'description'];
}
