<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Quiz_attempt_model extends Model
{
    protected $table = 'quiz_attempts';
    protected $fillable = ['user_id', 'quiz_id', 'score', 'total_questions', 'percentage', 'time_taken', 'completed_at'];
}
