<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Topic_model extends Model
{
    protected $table = 'topics';
    protected $fillable = ['subject_id', 'title', 'summary'];
}
