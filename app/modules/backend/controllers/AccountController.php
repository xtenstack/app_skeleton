<?php
declare(strict_types=1);

namespace App_skeleton\Modules\Backend\Controllers;

/**
 * Self-service "my profile" for whoever is logged in — distinct from
 * UsersController's admin-only profile editor, which manages *other*
 * users' profiles and is the only place age_verified_at can be set.
 */
class AccountController extends ControllerBase
{
    private const ALLOWED_AVATAR_TYPES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    private const MAX_AVATAR_BYTES = 5 * 1024 * 1024;

    public function indexAction()
    {
        $userId = $this->currentUserId();
        $user   = \Users::findFirstById($userId);

        $this->view->targetUser = $user;
        $this->view->profile    = $this->currentProfile($userId);
        $this->view->apiKeys    = \ApiKeys::find([
            'conditions' => 'user_id = :id: AND revoked_at IS NULL',
            'bind'       => ['id' => $userId],
            'order'      => 'id DESC',
            'limit'      => 5,
        ]);
    }

    public function updateAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/account'));
        }

        $userId = $this->currentUserId();
        $user   = \Users::findFirstById($userId);
        $user->first_name = (string) $this->request->getPost('first_name', 'string');
        $user->last_name  = (string) $this->request->getPost('last_name', 'string');

        if (!$user->save()) {
            foreach ($user->getMessages() as $message) {
                $this->flash->error((string) $message);
            }

            return $this->response->redirect($this->url->get('backend/account'));
        }

        $profile = $this->currentProfile($userId);
        $profile->phone    = (string) $this->request->getPost('phone', 'string');
        $profile->bio      = (string) $this->request->getPost('bio', 'string');
        $profile->timezone = (string) $this->request->getPost('timezone', 'string') ?: 'UTC';
        $profile->locale   = (string) $this->request->getPost('locale', 'string') ?: 'en-AU';

        if (!$profile->save()) {
            foreach ($profile->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Profile updated');
        }

        return $this->response->redirect($this->url->get('backend/account'));
    }

    public function uploadAvatarAction()
    {
        if (!$this->request->isPost() || !$this->request->hasFiles()) {
            $this->flash->error('No file was uploaded');

            return $this->response->redirect($this->url->get('backend/account'));
        }

        $file = $this->request->getUploadedFiles()[0];
        $mime = $file->getRealType();

        if (!isset(self::ALLOWED_AVATAR_TYPES[$mime])) {
            $this->flash->error('Avatar must be a JPEG, PNG, or WebP image');

            return $this->response->redirect($this->url->get('backend/account'));
        }

        if ($file->getSize() > self::MAX_AVATAR_BYTES) {
            $this->flash->error('Avatar must be under 5MB');

            return $this->response->redirect($this->url->get('backend/account'));
        }

        $userId = $this->currentUserId();
        $dir    = BASE_PATH . '/public/files/avatars';

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . self::ALLOWED_AVATAR_TYPES[$mime];
        $file->moveTo($dir . '/' . $filename);

        $profile     = $this->currentProfile($userId);
        $previousPath = $profile->avatar_path;
        $profile->avatar_path = '/files/avatars/' . $filename;
        $profile->save();

        if ($previousPath) {
            $previousFile = BASE_PATH . '/public' . $previousPath;

            if (is_file($previousFile)) {
                unlink($previousFile);
            }
        }

        $this->flash->success('Avatar updated');

        return $this->response->redirect($this->url->get('backend/account'));
    }

    private function currentUserId(): int
    {
        return (int) $this->session->get('auth')['id'];
    }

    private function currentProfile(int $userId): \UserProfiles
    {
        $profile = \UserProfiles::findFirst([
            'conditions' => 'user_id = :id:',
            'bind'       => ['id' => $userId],
        ]);

        if (!$profile) {
            $profile = new \UserProfiles();
            $profile->user_id = $userId;
        }

        return $profile;
    }
}
