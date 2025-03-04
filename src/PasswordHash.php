<?php
#
# Portable PHP password hashing framework.
#
# Version 0.5.4-amir / mod by Amir Moradi.
# Changelog:
# - In CheckPassword(), the hash_equals native php function is now used instead
#   of '===' comparaison to prevent timing attacks
# - Introducing more data to $random_state in the constuctor. (from okaresz.v1)
# - In gensalt_blowfish() if PHP_VERSION >= 5.3.7, use the new, fixed BLOWFISH salt id. (from okaresz.v1)
# - Improve get_random_bytes() by adding more methods and by introducing more data to the entropy pool. (from okaresz.v1)
#   Some of the code is from the SecurityMultiTool by padraic and from ircmaxell's RandomLib and password_compat.
#
# Written by Solar Designer <solar at openwall.com> in 2004-2006 and placed in
# the public domain.  Revised in subsequent years, still public domain.
#
# There's absolutely no warranty.
#
# The homepage URL for this framework is:
#
#	http://www.openwall.com/phpass/
#
# Please be sure to update the Version line if you edit this file in any way.
# It is suggested that you leave the main version number intact, but indicate
# your project name (after the slash) and add your own revision information.
#
# Please do not change the "private" password hashing method implemented in
# here, thereby making your hashes incompatible.  However, if you must, please
# change the hash type identifier (the "$P$") to something different.
#
# Obviously, since this code is in the public domain, the above are not
# requirements (there can be none), but merely suggestions.
#
class PasswordHash {
	var $itoa64;
	var $iteration_count_log2;
	var $portable_hashes;
	var $random_state;

	function __construct($iteration_count_log2, $portable_hashes)
	{	
		$this->itoa64 = './0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';

		if ($iteration_count_log2 < 4 || $iteration_count_log2 > 31) {
			$iteration_count_log2 = 8;
		}
		$this->iteration_count_log2 = $iteration_count_log2;

		$this->portable_hashes = $portable_hashes;

		$this->random_state = microtime();
		if (function_exists('getmypid')) {
			$this->random_state .= getmypid();
		}
		$this->random_state .= md5(serialize($_SERVER));
	}

	function PasswordHash($iteration_count_log2, $portable_hashes)
	{
		self::__construct($iteration_count_log2, $portable_hashes);
	}

	function get_random_bytes($count)
	{
		$output = '';
		
		if( function_exists('openssl_random_pseudo_bytes' ) )
		{
		    $output = openssl_random_pseudo_bytes($count, $usable);
		    if (true === $usable) {
			return $output;
		    }
		}
		if( function_exists('mcrypt_create_iv')
		    && (version_compare(PHP_VERSION, '5.3.0') >= 0 )
		    || (strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN')
		    && !defined('PHALANGER')
		) {
		    $output = mcrypt_create_iv($count, MCRYPT_DEV_URANDOM);
		    if ($output !== false && strlen($output) === $count) {
			return $output;
		    }
		}
		
		if (@is_readable('/dev/urandom') &&
		    ($fh = @fopen('/dev/urandom', 'rb'))) {
		    $output = fread($fh, $count);
		    fclose($fh);
		}
		
		if (strlen($output) < $count) {
		    $output = '';
		    $seed = microtime() . memory_get_usage();
		    if( function_exists('gc_collect_cycles') )
			{ gc_collect_cycles(); }
		    else
		    {
			$i = 0;
			while( $i < 32)
			    { $i += 1+(int)round(lcg_value()); }
		    }
		
		    $this->random_state .= $seed . microtime();
		    for ($i = 0; $i < $count; $i += 16)
		    {
		
			$this->random_state =  md5(microtime() . $this->random_state);
			$output .= pack('H*', md5(substr($this->random_state,0,16)));
		    }
		    $output = substr($output, 0, $count);
		}
		
		return $output;
	}

	function encode64($input, $count)
	{
		$output = '';
		$i = 0;
		do {
			$value = ord($input[$i++]);
			$output .= $this->itoa64[$value & 0x3f];
			if ($i < $count) {
				$value |= ord($input[$i]) << 8;
			}
			$output .= $this->itoa64[($value >> 6) & 0x3f];
			if ($i++ >= $count) {
				break;
			}
			if ($i < $count) {
				$value |= ord($input[$i]) << 16;
			}
			$output .= $this->itoa64[($value >> 12) & 0x3f];
			if ($i++ >= $count) {
				break;
			}
			$output .= $this->itoa64[($value >> 18) & 0x3f];
		} while ($i < $count);

		return $output;
	}

	function gensalt_private($input)
	{
		$output = '$P$';
		$output .= $this->itoa64[min($this->iteration_count_log2 + 5,
		    30)];
		$output .= $this->encode64($input, 6);

		return $output;
	}

	function crypt_private($password, $setting)
	{
		$output = '*0';
		if (substr($setting, 0, 2) === $output) {
			$output = '*1';
		}

		$id = substr($setting, 0, 3);
		# We use "$P$", phpBB3 uses "$H$" for the same thing
		if ($id !== '$P$' && $id !== '$H$') {
			return $output;
		}

		$count_log2 = strpos($this->itoa64, $setting[3]);
		if ($count_log2 < 7 || $count_log2 > 30) {
			return $output;
		}

		$count = 1 << $count_log2;

		$salt = substr($setting, 4, 8);
		if (strlen($salt) !== 8) {
			return $output;
		}

		# We were kind of forced to use MD5 here since it's the only
		# cryptographic primitive that was available in all versions
		# of PHP in use.  To implement our own low-level crypto in PHP
		# would have resulted in much worse performance and
		# consequently in lower iteration counts and hashes that are
		# quicker to crack (by non-PHP code).
		$hash = md5($salt . $password, TRUE);
		do {
			$hash = md5($hash . $password, TRUE);
		} while (--$count);

		$output = substr($setting, 0, 12);
		$output .= $this->encode64($hash, 16);

		return $output;
	}

	function gensalt_blowfish($input)
	{
		# This one needs to use a different order of characters and a
		# different encoding scheme from the one in encode64() above.
		# We care because the last character in our encoded string will
		# only represent 2 bits.  While two known implementations of
		# bcrypt will happily accept and correct a salt string which
		# has the 4 unused bits set to non-zero, we do not want to take
		# chances and we also do not want to waste an additional byte
		# of entropy.
		$itoa64 = './ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';

		$output = (version_compare(PHP_VERSION, '5.3.7') >= 0)? '$2y$' : '$2a$';
		$output .= chr((int)(ord('0') + $this->iteration_count_log2 / 10));
		$output .= chr(ord('0') + $this->iteration_count_log2 % 10);
		$output .= '$';

		$i = 0;
		do {
			$c1 = ord($input[$i++]);
			$output .= $itoa64[$c1 >> 2];
			$c1 = ($c1 & 0x03) << 4;
			if ($i >= 16) {
				$output .= $itoa64[$c1];
				break;
			}

			$c2 = ord($input[$i++]);
			$c1 |= $c2 >> 4;
			$output .= $itoa64[$c1];
			$c1 = ($c2 & 0x0f) << 2;

			$c2 = ord($input[$i++]);
			$c1 |= $c2 >> 6;
			$output .= $itoa64[$c1];
			$output .= $itoa64[$c2 & 0x3f];
		} while (1);

		return $output;
	}

	function HashPassword($password)
	{
		$random = '';

		if (CRYPT_BLOWFISH === 1 && !$this->portable_hashes) {
			$random = $this->get_random_bytes(16);
			$hash =
			    crypt($password, $this->gensalt_blowfish($random));
			if (strlen($hash) === 60) {
				return $hash;
			}
		}

		if (strlen($random) < 6) {
			$random = $this->get_random_bytes(6);
		}
		$hash =
		    $this->crypt_private($password,
		    $this->gensalt_private($random));
		if (strlen($hash) === 34) {
			return $hash;
		}

		# Returning '*' on error is safe here, but would _not_ be safe
		# in a crypt(3)-like function used _both_ for generating new
		# hashes and for validating passwords against existing hashes.
		return '*';
	}

	function CheckPassword($password, $stored_hash)
	{
		$hash = $this->crypt_private($password, $stored_hash);
		if ($hash[0] === '*') {
			$hash = crypt($password, $stored_hash);
		}

		return hash_equals($stored_hash, $hash);
	}
}
