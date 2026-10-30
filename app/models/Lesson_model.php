<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Lesson_model extends Model
{
    protected $table = 'lessons';
    protected $fillable = ['topic_id', 'title', 'content'];
}
