<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Quiz_question_model extends Model
{
    protected $table = 'quiz_questions';
    protected $fillable = ['quiz_id', 'question_text', 'type', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option', 'explanation'];
}
