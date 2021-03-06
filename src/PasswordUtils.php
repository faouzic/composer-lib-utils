<?php

namespace ETNA\Utils;

class PasswordUtils
{
    /**
     * Generates a password
     *
     * @param  integer $num_alpha     Number of letter wanted in the password
     * @param  integer $num_non_alpha Number of non-letter wanted in the password
     *
     * @return string                 Generated password
     */
    public static function generate($num_alpha = 6, $num_non_alpha = 2)
    {
        $list_alpha     = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
        $list_non_alpha = '.0123456789';

        $passwd = str_shuffle(
            substr(str_shuffle($list_alpha), 0, $num_alpha) .
            substr(str_shuffle($list_non_alpha), 0, $num_non_alpha)
        );

        return $passwd;
    }

    /**
     * Encrypt a password (BLACK MAGIC !!!! Don't touch this !!)
     *
     * @param  string $password Clear password to encrypt
     *
     * @return string           Encrypted password
     */
    public static function encrypt($password)
    {
        $salt = '$2a$07$' . self::generate(20, 5) . '$';

        $crypted = crypt($password, $salt);
        $crypted = crypt($password, $crypted);
        $crypted = crypt($password, $crypted);
        $crypted = crypt($password, $crypted);
        $crypted = crypt($password, $crypted);

        return $crypted;
    }
}
