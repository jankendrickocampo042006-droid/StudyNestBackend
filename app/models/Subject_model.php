<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Subject_model extends Model
{
    protected $table = 'subjects';
    protected $fillable = ['name', 'description'];
}
