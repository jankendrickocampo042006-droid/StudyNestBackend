<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Lesson_review_model extends Model
{
    protected $table = 'lesson_reviews';
    protected $fillable = ['user_id', 'lesson_id', 'reviewed_at'];
}
