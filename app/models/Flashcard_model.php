<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');

class Flashcard_model extends Model
{
    protected $table = 'flashcards';
    protected $fillable = ['study_set_id', 'term_question', 'answer_definition'];
}
