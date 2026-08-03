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

    private const ALLOWED_PALETTES = ['blue', 'purple', 'green', 'orange'];
    private const ALLOWED_MODES    = ['light', 'dark', 'auto'];

    public function indexAction()
    {
        $userId = $this->currentUserId();
        $user   = \Users::findFirstById($userId);

        $userSettings = $this->session->get('user_settings') ?? [];

        $this->view->targetUser     = $user;
        $this->view->profile        = $this->currentProfile($userId);
        $this->view->themePalette   = $userSettings['theme_palette'] ?? $this->settings->get('default_theme_palette', 'blue');
        $this->view->themeMode      = $userSettings['theme_mode'] ?? $this->settings->get('default_theme_mode', 'auto');
        $this->view->timezones      = \App_skeleton\LocaleOptions::timezones();
        $this->view->locales        = \App_skeleton\LocaleOptions::locales();
        $this->view->apiKeys        = \ApiKeys::find([
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

        $timezone = (string) $this->request->getPost('timezone', 'string') ?: 'UTC';
        $locale   = (string) $this->request->getPost('locale', 'string') ?: 'en-AU';

        if (!\App_skeleton\LocaleOptions::isValidTimezone($timezone) || !\App_skeleton\LocaleOptions::isValidLocale($locale)) {
            $this->flash->error('Invalid timezone or locale selection');

            return $this->response->redirect($this->url->get('backend/account'));
        }

        $profile = $this->currentProfile($userId);
        $profile->phone    = (string) $this->request->getPost('phone', 'string');
        $profile->bio      = (string) $this->request->getPost('bio', 'string');
        $profile->timezone = $timezone;
        $profile->locale   = $locale;

        if (!$profile->save()) {
            foreach ($profile->getMessages() as $message) {
                $this->flash->error((string) $message);
            }
        } else {
            $this->flash->success('Profile updated');
        }

        return $this->response->redirect($this->url->get('backend/account'));
    }

    public function saveThemeAction()
    {
        if (!$this->request->isPost()) {
            return $this->response->redirect($this->url->get('backend/account'));
        }

        $palette = (string) $this->request->getPost('theme_palette', 'string');
        $mode    = (string) $this->request->getPost('theme_mode', 'string');

        if (!in_array($palette, self::ALLOWED_PALETTES, true) || !in_array($mode, self::ALLOWED_MODES, true)) {
            $this->flash->error('Invalid theme selection');

            return $this->response->redirect($this->url->get('backend/account'));
        }

        $userId = $this->currentUserId();

        $this->upsertSetting($userId, 'theme_palette', $palette);
        $this->upsertSetting($userId, 'theme_mode', $mode);

        // Also update the session copy so the new theme applies on this
        // response's own redirect, not just after the next login.
        $userSettings                   = $this->session->get('user_settings') ?? [];
        $userSettings['theme_palette']  = $palette;
        $userSettings['theme_mode']     = $mode;
        $this->session->set('user_settings', $userSettings);

        $this->flash->success('Theme updated');

        return $this->response->redirect($this->url->get('backend/account'));
    }

    private function upsertSetting(int $userId, string $key, string $value): void
    {
        $setting = \UserSettings::findFirst([
            'conditions' => 'user_id = :user_id: AND setting_key = :key:',
            'bind'       => ['user_id' => $userId, 'key' => $key],
        ]) ?: new \UserSettings();

        $setting->user_id       = $userId;
        $setting->setting_key   = $key;
        $setting->setting_value = $value;
        $setting->save();
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
