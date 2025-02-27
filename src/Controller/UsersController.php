<?php
namespace App\Controller;

use App\Controller\AppController;
use Cake\ORM\TableRegistry;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Exception\MethodNotAllowedException;
use Cake\Core\Configure;
use Cake\Utility\Security;

class UsersController extends AppController
{
    public $filterFields = ['Individuals.uuid', 'username', 'Individuals.email', 'Individuals.first_name', 'Individuals.last_name', 'Organisations.name'];
    public $quickFilterFields = ['Individuals.uuid', ['username' => true], ['Individuals.first_name' => true], ['Individuals.last_name' => true], 'Individuals.email'];
    public $containFields = ['Individuals', 'Roles', 'UserSettings', 'Organisations'];

    public function index()
    {
        $currentUser = $this->ACL->getUser();
        $conditions = [];
        if (empty($currentUser['role']['perm_admin'])) {
            $conditions['organisation_id'] = $currentUser['organisation_id'];
        }
        $keycloakUsersParsed = null;
        if (!empty(Configure::read('keycloak.enabled'))) {
            // $keycloakUsersParsed = $this->Users->getParsedKeycloakUser();
        }
        $this->CRUD->index([
            'contain' => $this->containFields,
            'filters' => $this->filterFields,
            'quickFilters' => $this->quickFilterFields,
            'conditions' => $conditions,
            'afterFind' => function($data) use ($keycloakUsersParsed) {
                // TODO: We might want to uncomment this at some point Still need to evaluate the impact
                // if (!empty(Configure::read('keycloak.enabled'))) {
                //     $keycloakUser = $keycloakUsersParsed[$data->username];
                //     $data['keycloak_status'] = array_values($this->Users->checkKeycloakStatus([$data->toArray()], [$keycloakUser]))[0];
                // }
                return $data;
            }
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set(
            'validRoles',
            $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0])->all()->toArray()
        );
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function add()
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        $individuals_params = [
            'sort' => ['email' => 'asc']
        ];
        $individual_ids = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0, 'perm_org_admin' => 0])->all()->toArray();
            $individual_ids = $this->Users->Individuals->find('aligned', ['organisation_id' => $currentUser['organisation_id']])->all()->extract('id')->toArray();
            if (empty($individual_ids)) {
                $individual_ids = [-1];
            }
            $individuals_params['conditions'] = ['id IN' => $individual_ids];
        } else {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        $defaultRole = $this->Users->Roles->find()->select(['id'])->where(['is_default' => true])->first()->toArray();
        $individuals = $this->Users->Individuals->find('list', $individuals_params)->toArray();
        $this->CRUD->add([
            'beforeMarshal' => function($data) {
                if (empty($data['password'])) {
                    $data['password'] = Security::randomString(20);
                }
                return $data;
            },
            'beforeSave' => function($data) use ($currentUser, $validRoles, $defaultRole, $individual_ids) {
                if (!isset($data['role_id']) && !empty($defaultRole)) {
                    $data['role_id'] = $defaultRole['id'];
                }
                if (!$currentUser['role']['perm_admin']) {
                    $data['organisation_id'] = $currentUser['organisation_id'];
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You do not have permission to assign that role.'));
                    }
                }
                if ((!isset($data['individual_id']) || $data['individual_id'] === 'new') && !empty($data['individual'])) {
                    $existingOrg = $this->Users->Organisations->find('all')->where(['id' => $data['organisation_id']])->select(['uuid'])->first();
                    if (empty($existingOrg)) {
                        throw new MethodNotAllowedException(__('No valid organisation found. Either encode the organisation separately or select a valid one.'));
                    }
                    $data['individual']['alignments'][] = ['type' => 'Member', 'organisation' => ['uuid' => $existingOrg['uuid']]];
                    $data['individual_id'] = $this->Users->Individuals->captureIndividual($data['individual']);
                } else if (!$currentUser['role']['perm_admin'] && isset($data['individual_id'])) {
                    if (!in_array($data['individual_id'], $individual_ids)) {
                        throw new MethodNotAllowedException(__('The selected individual is not aligned with your organisation. Creating a user for them is not permitted.'));
                    }
                }
                if (empty($data['individual_id'])) {
                    throw new MethodNotAllowedException(__('No valid individual found. Either supply it in the request or set the individual_id to a valid value.'));
                }
                if (Configure::read('keycloak.enabled')) {
                    $existingUserForIndividual = $this->Users->find()->where(['individual_id' => $data['individual_id']])->first();
                    if (!empty($existingUserForIndividual)) {
                        throw new MethodNotAllowedException(__('Invalid individual selected - when KeyCloak is enabled, only one user account may be assigned to an individual.'));
                    }
                }
                return $data;
            },
            'afterSave' => function($data) {
                if (Configure::read('keycloak.enabled')) {
                    $this->Users->enrollUserRouter($data);
                }
            }
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        /*
        $alignments = $this->Users->Individuals->Alignments->find('list', [
            //'keyField' => 'id',
            'valueField' => 'organisation_id',
            'groupField' => 'individual_id'
        ])->toArray();
        $alignments = array_map(function($value) { return array_values($value); }, $alignments);
        */
        $org_conditions = [];
        if (empty($currentUser['role']['perm_admin'])) {
            $org_conditions = ['id' => $currentUser['organisation_id']];
        }
        $dropdownData = [
            'role' => $validRoles,
            'individual' => $individuals,
            'organisation' => $this->Users->Organisations->find('list', [
                'sort' => ['name' => 'asc'],
                'conditions' => $org_conditions
            ])->toArray()
        ];
        $this->set(compact('dropdownData'));
        $this->set('defaultRole', $defaultRole['id'] ?? null);
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function view($id = false)
    {
        $currentUser = $this->ACL->getUser();
        if (empty($id) || (empty($currentUser['role']['perm_org_admin']) && empty($currentUser['role']['perm_admin']))) {
            $id = $this->ACL->getUser()['id'];
        }
        $keycloakUsersParsed = null;
        if (!empty(Configure::read('keycloak.enabled'))) {
            $keycloakUsersParsed = $this->Users->getParsedKeycloakUser();
        }
        $this->CRUD->view($id, [
            'contain' => ['Individuals' => ['Alignments' => 'Organisations'], 'Roles', 'Organisations'],
            'afterFind' => function($data) use ($keycloakUsersParsed) {
                $data = $this->fetchTable('PermissionLimitations')->attachLimitations($data);
                if (!empty(Configure::read('keycloak.enabled'))) {
                    $keycloakUser = $keycloakUsersParsed[$data->username] ?? [];
                    $data['keycloak_status'] = array_values($this->Users->checkKeycloakStatus([$data->toArray()], [$keycloakUser]))[0];
                }
                return $data;
            }
        ]);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('keycloakConfig', Configure::read('keycloak', ['enabled' => false]));
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function edit($id = false)
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        $individuals_params = [
            'sort' => ['email' => 'asc']
        ];
        $individual_ids = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->select(['id', 'name'])->order(['name' => 'asc'])->where(['perm_admin' => 0, 'perm_org_admin' => 0])->all()->toArray();
            $individual_ids = $this->Users->Individuals->find('aligned', ['organisation_id' => $currentUser['organisation_id']])->all()->extract('id')->toArray();
            if (empty($individual_ids)) {
                $individual_ids = [-1];
            }
            $individuals_params['conditions'] = ['id IN' => $individual_ids];
        } else {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        if (empty($id)) {
            $id = $currentUser['id'];
        } else {
            $id = intval($id);
        }

        $params = [
            'removeEmpty' => [
                'password'
            ],
            'fields' => [
                'password', 'confirm_password'
            ],
            'contain' => ['Roles', ],
        ];
        if ($this->request->is(['get'])) {
            $params['fields'] = array_merge($params['fields'], ['individual_id', 'role_id', 'disabled']);
            if (!empty($this->ACL->getUser()['role']['perm_admin'])) {
                $params['fields'][] = 'organisation_id';
            }
            if (!$currentUser['role']['perm_admin']) {
                $params['afterFind'] = function ($user, &$params) use ($currentUser) {
                    if (!empty($user)) { // We don't have a 404
                        if (!$this->ACL->canEditUser($currentUser, $user)) {
                            throw new MethodNotAllowedException(__('You cannot edit the given user.'));
                        }
                    }
                    return $user;
                };
            }
        }
        if ($this->request->is(['post', 'put']) && !empty($this->ACL->getUser()['role']['perm_admin'])) {
            $params['fields'][] = 'individual_id';
            $params['fields'][] = 'role_id';
            $params['fields'][] = 'organisation_id';
            $params['fields'][] = 'disabled';
        } else if ($this->request->is(['post', 'put']) && !empty($this->ACL->getUser()['role']['perm_org_admin'])) {
            $params['fields'][] = 'role_id';
            $params['fields'][] = 'disabled';
            if (!$currentUser['role']['perm_admin']) {
                $params['afterFind'] = function ($data, &$params) use ($currentUser, $validRoles) {
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You cannot edit the given privileged user.'));
                    }
                    if (!$this->ACL->canEditUser($currentUser, $data)) {
                        throw new MethodNotAllowedException(__('You cannot edit the given user.'));
                    }
                    return $data;
                };
                $params['beforeSave'] = function ($data) use ($currentUser, $validRoles) {
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You cannot assign the chosen role to a user.'));
                    }
                    return $data;
                };
            }
        }
        $this->CRUD->edit($id, $params);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $dropdownData = [
            'role' => $validRoles,
            'individual' => $this->Users->Individuals->find('list', [
                'sort' => ['email' => 'asc']
            ]),
            'organisation' => $this->Users->Organisations->find('list', [
                'sort' => ['name' => 'asc']
            ])
        ];
        $org_conditions = [];
        if (empty($currentUser['role']['perm_admin'])) {
            $org_conditions = ['id' => $currentUser['organisation_id']];
        }
        $dropdownData = [
            'role' => $validRoles,
            'individual' => $this->Users->Individuals->find('list', $individuals_params)->toArray(),
            'organisation' => $this->Users->Organisations->find('list', [
                'sort' => ['name' => 'asc'],
                'conditions' => $org_conditions
            ])->toArray()
        ];
        $this->set(compact('dropdownData'));
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
        $this->render('add');
    }

    public function toggle($id, $fieldName = 'disabled')
    {
        $params = [
            'contain' => 'Roles'
        ];
        $currentUser = $this->ACL->getUser();
        if (!$currentUser['role']['perm_admin']) {
            $params['afterFind'] = function ($user, &$params) use ($currentUser) {
                if (!$this->ACL->canEditUser($currentUser, $user)) {
                    throw new MethodNotAllowedException(__('You cannot edit the given user.'));
                }
                return $user;
            };
        }
        $this->CRUD->toggle($id, $fieldName, $params);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
    }

    public function delete($id)
    {
        $currentUser = $this->ACL->getUser();
        $validRoles = [];
        if (!$currentUser['role']['perm_admin']) {
            $validRoles = $this->Users->Roles->find('list')->order(['name' => 'asc'])->all()->toArray();
        }
        $params = [
            'beforeSave' => function($data) use ($currentUser, $validRoles) {
                if (!$currentUser['role']['perm_admin']) {
                    if ($data['organisation_id'] !== $currentUser['organisation_id']) {
                        throw new MethodNotAllowedException(__('You do not have permission to delete the given user.'));
                    }
                    if (!in_array($data['role_id'], array_keys($validRoles))) {
                        throw new MethodNotAllowedException(__('You do not have permission to delete the given user.'));
                    }
                }
                if (Configure::read('keycloak.enabled')) {
                    if (!$this->Users->deleteUser($data)) {
                        throw new MethodNotAllowedException(__('Could not delete the user from KeyCloak. Please try again later, or consider disabling the user instead.'));
                    }
                }
                return $data;
            }
        ];
        $this->CRUD->delete($id, $params);
        $responsePayload = $this->CRUD->getResponsePayload();
        if (!empty($responsePayload)) {
            return $responsePayload;
        }
        $this->set('metaGroup', $this->isAdmin ? 'Administration' : 'Cerebrate');
    }

    public function login()
    {
        $blocked = false;
        if ($this->request->is('post')) {
            $BruteforceTable = TableRegistry::getTableLocator()->get('Bruteforces');
            $input = $this->request->getData();
            $blocked = $BruteforceTable->isBlocklisted($_SERVER['REMOTE_ADDR'], $input['username']);
            if ($blocked) {
                $this->Authentication->logout();
                $this->Flash->error(__('Too many attempts, brute force protection triggered. Wait 5 minutes before trying again.'));
                $this->redirect(['controller' => 'users', 'action' => 'login']);
            }
        }
        if (!$blocked) {
            $result = $this->Authentication->getResult();
            // If the user is logged in send them away.
            $logModel = $this->Users->auditLogs();
            if ($result->isValid()) {
                $user = $logModel->userInfo();
                $logModel->insert([
                    'request_action' => 'login',
                    'model' => 'Users',
                    'model_id' => $user['id'],
                    'model_title' => $user['name'],
                    'changed' => []
                ]);
                $target = $this->Authentication->getLoginRedirect() ?? '/instance/home';
                return $this->redirect($target);
            }
            if ($this->request->is('post') && !$result->isValid()) {
                $BruteforceTable->insert($_SERVER['REMOTE_ADDR'], $input['username']);
                $logModel->insert([
                    'request_action' => 'login_fail',
                    'model' => 'Users',
                    'model_id' => 0,
                    'model_title' => 'unknown_user',
                    'changed' => []
                ]);
                $this->Flash->error(__('Invalid username or password'));
            }
        }
        $this->viewBuilder()->setLayout('login');
    }

    public function logout()
    {
        $result = $this->Authentication->getResult();
        if ($result->isValid()) {
            $logModel = $this->Users->auditLogs();
            $user = $logModel->userInfo();
            $logModel->insert([
                'request_action' => 'logout',
                'model' => 'Users',
                'model_id' => $user['id'],
                'model_title' => $user['name'],
                'changed' => []
            ]);
            $this->Authentication->logout();
            $this->Flash->success(__('Goodbye.'));
            if (Configure::read('keycloak.enabled')) {
                $this->redirect($this->Users->keyCloaklogout());
            }
            return $this->redirect(\Cake\Routing\Router::url('/users/login'));
        }
    }

    public function settings($user_id=false)
    {
        $editingAnotherUser = false;
        $currentUser = $this->ACL->getUser();
        if (empty($currentUser['role']['perm_admin']) || $user_id == $currentUser->id) {
            $user = $currentUser;
        } else {
            $user = $this->Users->get($user_id, [
                'contain' => ['Roles', 'Individuals' => 'Organisations', 'Organisations', 'UserSettings']
            ]);
            $editingAnotherUser = true;
        }
        $this->set('editingAnotherUser', $editingAnotherUser);
        $this->set('user', $user);
        $all = $this->Users->UserSettings->getSettingsFromProviderForUser($user->id, true);
        $this->set('settingsProvider', $all['settingsProvider']);
        $this->set('settings', $all['settings']);
        $this->set('settingsFlattened', $all['settingsFlattened']);
        $this->set('notices', $all['notices']);
    }

    public function register()
    {
        if (empty(Configure::read('security.registration.self-registration'))) {
            throw new UnauthorizedException(__('User self-registration is not open.'));
        }
        if (!Configure::check('security.registration.floodProtection') || Configure::read('security.registration.floodProtection')) {
            $this->FloodProtection->check('register');
        }
        if ($this->request->is('post')) {
            $data = $this->request->getData();
            $this->InboxProcessors = TableRegistry::getTableLocator()->get('InboxProcessors');
            $processor = $this->InboxProcessors->getProcessor('User', 'Registration');
            $data = [
                'origin' => $this->request->clientIp(),
                'comment' => '-no comment-',
                'data' => [
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'password' => $data['password'],
                    'org_name' => $data['org_name'],
                    'org_uuid' => $data['org_uuid'],
                ],
            ];
            $processorResult = $processor->create($data);
            if (!empty(Configure::read('security.registration.floodProtection'))) {
                $this->FloodProtection->set('register');
            }
            return $processor->genHTTPReply($this, $processorResult, ['controller' => 'Inbox', 'action' => 'index']);
        }
        $this->viewBuilder()->setLayout('login');
    }
}
