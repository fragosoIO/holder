<?php

declare(strict_types=1);

use App\Api;
use App\Api\AgentEndpoints;
use App\Api\CompanyEndpoints;
use App\Api\FloorEndpoints;
use App\Api\HealthAction;
use App\Api\OnboardingEndpoints;
use App\Api\SessionEndpoints;
use App\Api\WorkEndpoints;
use Yiisoft\Router\Group;
use Yiisoft\Router\Route;

return [
    Route::get('/')->action(Api\IndexAction::class)->name('app/index'),
    Route::get('/api/v1/health')->action(HealthAction::class)->name('health'),

    Group::create('/api/v1')
        ->middleware(Api\ActorMiddleware::class, Api\HolderExceptionMiddleware::class)
        ->routes(
            Route::get('/session')->action([SessionEndpoints::class, 'show'])->name('session/show'),
            Route::post('/session')->action([SessionEndpoints::class, 'create'])->name('session/create'),
            Route::delete('/session')->action([SessionEndpoints::class, 'destroy'])->name('session/destroy'),

            Route::get('/companies')->action([CompanyEndpoints::class, 'list'])->name('companies/list'),
            Route::post('/companies')->action([CompanyEndpoints::class, 'create'])->name('companies/create'),
            Route::post('/onboarding')->action([OnboardingEndpoints::class, 'complete'])->name('onboarding/complete'),
            Route::get('/companies/{companyId}')->action([CompanyEndpoints::class, 'show'])->name('companies/show'),
            Route::patch('/companies/{companyId}')->action([CompanyEndpoints::class, 'update'])->name('companies/update'),
            Route::put('/companies/{companyId}/github-token')->action([CompanyEndpoints::class, 'saveGithubToken'])->name('companies/github-token'),
            Route::post('/companies/{companyId}/invites')->action([CompanyEndpoints::class, 'invite'])->name('companies/invite'),
            Route::post('/invites/{token}/accept')->action([CompanyEndpoints::class, 'acceptInvite'])->name('invites/accept'),

            Route::get('/companies/{companyId}/pi/models')->action([AgentEndpoints::class, 'catalog'])->name('pi/models'),
            Route::get('/companies/{companyId}/agents')->action([AgentEndpoints::class, 'list'])->name('agents/list'),
            Route::post('/companies/{companyId}/agents')->action([AgentEndpoints::class, 'create'])->name('agents/create'),
            Route::get('/companies/{companyId}/agents/{agentId}')->action([AgentEndpoints::class, 'show'])->name('agents/show'),
            Route::patch('/companies/{companyId}/agents/{agentId}')->action([AgentEndpoints::class, 'update'])->name('agents/update'),
            Route::post('/companies/{companyId}/agents/{agentId}/pause')->action([AgentEndpoints::class, 'pause'])->name('agents/pause'),
            Route::post('/companies/{companyId}/agents/{agentId}/resume')->action([AgentEndpoints::class, 'resume'])->name('agents/resume'),
            Route::post('/companies/{companyId}/agents/{agentId}/terminate')->action([AgentEndpoints::class, 'terminate'])->name('agents/terminate'),
            Route::get('/companies/{companyId}/floor')->action([FloorEndpoints::class, 'show'])->name('floor/show'),

            Route::get('/companies/{companyId}/goals')->action([WorkEndpoints::class, 'listGoals'])->name('goals/list'),
            Route::post('/companies/{companyId}/goals')->action([WorkEndpoints::class, 'createGoal'])->name('goals/create'),
            Route::get('/companies/{companyId}/goals/{goalId}')->action([WorkEndpoints::class, 'showGoal'])->name('goals/show'),
            Route::get('/companies/{companyId}/projects')->action([WorkEndpoints::class, 'listProjects'])->name('projects/list'),
            Route::post('/companies/{companyId}/projects')->action([WorkEndpoints::class, 'createProject'])->name('projects/create'),
            Route::get('/companies/{companyId}/tasks')->action([WorkEndpoints::class, 'listTasks'])->name('tasks/list'),
            Route::post('/companies/{companyId}/tasks')->action([WorkEndpoints::class, 'createTask'])->name('tasks/create'),
            Route::get('/companies/{companyId}/tasks/{taskId}')->action([WorkEndpoints::class, 'showTask'])->name('tasks/show'),
            Route::patch('/companies/{companyId}/tasks/{taskId}')->action([WorkEndpoints::class, 'updateTask'])->name('tasks/update'),
            Route::post('/companies/{companyId}/tasks/{taskId}/comments')->action([WorkEndpoints::class, 'comment'])->name('tasks/comment'),
            Route::post('/companies/{companyId}/tasks/{taskId}/opening-answer')->action([WorkEndpoints::class, 'answerOpening'])->name('tasks/opening-answer'),
            Route::post('/companies/{companyId}/tasks/{taskId}/question-answer')->action([WorkEndpoints::class, 'answerQuestions'])->name('tasks/question-answer'),
            Route::post('/companies/{companyId}/tasks/{taskId}/cancel')->action([WorkEndpoints::class, 'cancel'])->name('tasks/cancel'),
            Route::get('/companies/{companyId}/tasks/{taskId}/stream')->action([WorkEndpoints::class, 'stream'])->name('tasks/stream'),

            Route::post('/agent/tasks/{taskId}/comments')->action([WorkEndpoints::class, 'agentComment'])->name('agent/comment'),
            Route::post('/agent/tasks/{taskId}/questions')->action([WorkEndpoints::class, 'askQuestions'])->name('agent/questions'),
            Route::post('/agent/tasks/{taskId}/status')->action([WorkEndpoints::class, 'agentStatus'])->name('agent/status'),
            Route::post('/agent/tasks/{taskId}/assign')->action([WorkEndpoints::class, 'agentAssign'])->name('agent/assign'),
        ),
];
