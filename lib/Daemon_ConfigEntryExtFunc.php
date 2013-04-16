<?php

/**
 * External function config entry
 *
 * @package Core
 * @subpackage Config
 *
 * @author Zorin Vasily <kak.serpom.po.yaitsam@gmail.com>
 */
class Daemon_ConfigEntryExtFunc extends Daemon_ConfigEntry {

	public static function HumanToPlain($value) {
		$cb = include($value);
		return is_callable($cb) ? $cb : null;
	}

}
