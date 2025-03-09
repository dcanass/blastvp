<?php
namespace Objects\CLI;

use Ahc\Cli\Input\Command;
use Ahc\Cli\IO\Interactor;
use Ahc\Cli\Output\Color;
use Controllers\Panel;

class ResetPasswordCommand extends Command {
    public function __construct() {
        parent::__construct('reset-password', 'reset the password for a user');

        $this
            ->option('-u --user', 'email of the user')
            ->option('-p --password', 'password to set');
    }

    // This method is auto called before `self::execute()` and receives `Interactor $io` instance
    public function interact(Interactor $io): void {
        if (!$this->user) {
            $this->set('user', $io->prompt('E-Mail'));
        }

        if (!$this->password) {
            $newPass = $io->promptHidden('New password');

            $confirmFn = function ($e) use ($newPass) {
                if ($e !== $newPass) {
                    throw new \InvalidArgumentException('Passwords don\'t match');
                }
            };

            $io->promptHidden('Confirm new password', $confirmFn);
            $this->set('password', $newPass);
        }
    }

    public function execute($user, $password) {
        $io = $this->app()->io();
        $us = Panel::getDatabase()->fetch_single_row('users', 'email', $user);
        if (!$us) {
            throw new \InvalidArgumentException('No such user with this email found.');
        }

        $io->info('User found', true);

        $io->info("Setting password for user: $user", true);

        Panel::getDatabase()->update('users', [
            'password' => password_hash($password, PASSWORD_DEFAULT)], 'id', $us->id);

        $io->ok('Password set', true);
        return 0;
    }
}