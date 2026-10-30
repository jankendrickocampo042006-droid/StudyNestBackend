<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Favorite_lesson_model extends Model
{
    protected $table = 'favorite_lessons';
    protected $fillable = ['user_id', 'lesson_id'];
}
