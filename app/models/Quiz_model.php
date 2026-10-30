<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Quiz_model extends Model
{
    protected $table = 'quizzes';
    protected $fillable = ['topic_id', 'title', 'duration_minutes'];
}
