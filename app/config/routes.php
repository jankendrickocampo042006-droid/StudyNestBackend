<?php
defined('PREVENT_DIRECT_ACCESS') OR exit('No direct script access allowed');
/**
 * ------------------------------------------------------------------
 * LavaLust - an opensource lightweight PHP MVC Framework
 * ------------------------------------------------------------------
 *
 * MIT License
 *
 * Copyright (c) 2020 Ronald M. Marasigan
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 *
 * @package LavaLust
 * @author Ronald M. Marasigan <ronald.marasigan@yahoo.com>
 * @since Version 1
 * @link https://github.com/ronmarasigan/LavaLust
 * @license https://opensource.org/licenses/MIT MIT License
 */

/*
| -------------------------------------------------------------------
| URI ROUTING
| -------------------------------------------------------------------
| Here is where you can register web routes for your application.
|
|
*/
/** @var object $router **/

$router->get('/', 'Welcome::index');

$router->get('/api/health', 'StudyNestAuthApi::health');
$router->get('/api/bootstrap', 'StudyNestLearningApi::bootstrap');
$router->post('/api/auth/register', 'StudyNestAuthApi::register');
$router->post('/api/auth/login', 'StudyNestAuthApi::login');
$router->post('/api/auth/verify-email', 'StudyNestAuthApi::verify_email');
$router->post('/api/auth/resend-otp', 'StudyNestAuthApi::resend_otp');
$router->post('/api/auth/forgot-password', 'StudyNestAuthApi::forgot_password');
$router->post('/api/auth/reset-password', 'StudyNestAuthApi::reset_password');
$router->post('/api/auth/logout', 'StudyNestAuthApi::logout');
$router->get('/api/profile', 'StudyNestLearningApi::profile');
$router->put('/api/profile', 'StudyNestLearningApi::profile');
$router->get('/api/study-sets', 'StudyNestLearningApi::study_sets');
$router->post('/api/study-sets', 'StudyNestLearningApi::study_sets');
$router->get('/api/study-sets/{id}', 'StudyNestLearningApi::study_set');
$router->put('/api/study-sets/{id}', 'StudyNestLearningApi::study_set');
$router->delete('/api/study-sets/{id}', 'StudyNestLearningApi::study_set');
$router->get('/api/schedules', 'StudyNestLearningApi::schedules');
$router->post('/api/schedules', 'StudyNestLearningApi::schedules');
$router->delete('/api/schedules/{id}', 'StudyNestLearningApi::schedule');
$router->get('/api/favorites', 'StudyNestLearningApi::favorites');
$router->post('/api/favorites', 'StudyNestLearningApi::favorites');
$router->delete('/api/favorites/{id}', 'StudyNestLearningApi::favorite');
$router->post('/api/lessons/{id}/review', 'StudyNestLearningApi::review_lesson');
$router->get('/api/lessons/{id}/pdf', 'StudyNestLearningApi::lesson_pdf');
$router->get('/api/quizzes/{id}', 'StudyNestLearningApi::quiz');
$router->post('/api/quizzes/{id}/attempts', 'StudyNestLearningApi::submit_quiz');
$router->get('/api/manage', 'StudyNestManageApi::manage');
$router->get('/api/manage/teachers', 'StudyNestManageApi::manage_teachers');
$router->post('/api/manage/teachers', 'StudyNestManageApi::manage_teachers');
$router->get('/api/manage/teacher-applications', 'StudyNestManageApi::manage_teacher_applications');
$router->put('/api/manage/teacher-applications/{id}', 'StudyNestManageApi::manage_teacher_application');
$router->get('/api/manage/teacher-applications/{id}/photo', 'StudyNestManageApi::teacher_application_photo');
$router->get('/api/manage/subjects', 'StudyNestManageApi::manage_subjects');
$router->post('/api/manage/subjects', 'StudyNestManageApi::manage_subjects');
$router->put('/api/manage/subjects/{id}', 'StudyNestManageApi::manage_subject');
$router->delete('/api/manage/subjects/{id}', 'StudyNestManageApi::manage_subject');
$router->get('/api/manage/topics', 'StudyNestManageApi::manage_topics');
$router->post('/api/manage/topics', 'StudyNestManageApi::manage_topics');
$router->put('/api/manage/topics/{id}', 'StudyNestManageApi::manage_topic');
$router->delete('/api/manage/topics/{id}', 'StudyNestManageApi::manage_topic');
$router->get('/api/manage/lessons', 'StudyNestManageApi::manage_lessons');
$router->post('/api/manage/lessons', 'StudyNestManageApi::manage_lessons');
$router->put('/api/manage/lessons/{id}', 'StudyNestManageApi::manage_lesson');
$router->delete('/api/manage/lessons/{id}', 'StudyNestManageApi::manage_lesson');
$router->get('/api/manage/quizzes', 'StudyNestManageApi::manage_quizzes');
$router->post('/api/manage/quizzes', 'StudyNestManageApi::manage_quizzes');
$router->put('/api/manage/quizzes/{id}', 'StudyNestManageApi::manage_quiz');
$router->delete('/api/manage/quizzes/{id}', 'StudyNestManageApi::manage_quiz');