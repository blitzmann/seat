<?php

namespace Seat\EveApi;

use Pheal\Pheal;
use Pheal\Core\Config as PhealConfig;
use Carbon\Carbon;

/*
|--------------------------------------------------------------------------
| BaseAPI
|--------------------------------------------------------------------------
|
| The base EVE API Class, with some helper functions.
| All API calls extend this class.
|
*/

class BaseApi {

	/*
	|--------------------------------------------------------------------------
	| bootstrap()
	|--------------------------------------------------------------------------
	|
	| Configure Pheal\Pheal
	|
	*/

	public static function bootstrap()
	{
		PhealConfig::getInstance()->cache = new \Pheal\Cache\FileStorage( storage_path(). '/cache/phealcache/' );
		PhealConfig::getInstance()->access = new \Pheal\Access\StaticCheck();
		PhealConfig::getInstance()->log = new \Pheal\Log\FileStorage( storage_path() . '/logs/' );
		PhealConfig::getInstance()->http_user_agent = 'SeAT ' . \Config::get('seat.version') . 'API Fetcher';
		PhealConfig::getInstance()->api_customkeys = true;
		PhealConfig::getInstance()->http_method = 'curl';
	}

	/*
	|--------------------------------------------------------------------------
	| validateKeyPair()
	|--------------------------------------------------------------------------
	|
	| Check that a give keyID & vCode is valid
	|
	*/

	public static function validateKeyPair($keyID, $vCode)
	{
		if (!is_numeric($keyID))
			throw new \Exception('A API keyID must be a integer, we got: ' . $keyID);

		if (strlen($vCode) <> 64)
			throw new \Exception('A vCode should be 64 chars long, we got: ' . $vCode);
	}

	/*
	|--------------------------------------------------------------------------
	| makeCallHash()
	|--------------------------------------------------------------------------
	|
	| Generate a MD5 hash based on the 3 received arguements for caching related
	| information in the database.
	|
	*/

	public static function makeCallHash($api, $scope, $owner)
	{
		return md5(implode(',', array($api, $scope, $owner) ));
	}

	/*
	|--------------------------------------------------------------------------
	| disableKey()
	|--------------------------------------------------------------------------
	|
	| Sets the `isOk` field in the `seat_keys` table to 0, effectively marking the
	| key as inactive.
	|
	*/

	public static function disableKey($keyID, $error = null)
	{
		$key = \SeatKey::where('keyID', '=', $keyID)->first();	

		if (!$key)
			throw new Exception('Unable to find the entry in `seat_keys` to disable key: ' . $keyID);

		$key->isOk = 0;
		$key->lastError = $error;
		$key->save();
	}

	/*
	|--------------------------------------------------------------------------
	| banCall()
	|--------------------------------------------------------------------------
	|
	| Records a API call as banned together with the access mask.
	|
	*/

	public static function banCall($api, $scope, $owner = 0, $accessMask = 0, $reason = null)
	{

		\Log::warning('Processing a ban request for api: ' . $api . ' scope: ' . $scope . ' owner: ' . $owner, array('src' => __CLASS__));

		// Check if we should retreive the current access mask
		if ($accessMask == 0)
			$accessMask = \EveAccountAPIKeyInfo::where('keyID', '=', $owner)->pluck('accessMask');

		// Generate a hash with which to ID this call
		$hash = BaseApi::makeCallHash($api, $scope, $owner . $accessMask);

		// Check the cache if a ban has been recorded
		if (!\Cache::has('call_ban_grace_count_' . $hash)) {

			// Record the new ban, getting the grance period from the seat config and return
			\Cache::put('call_ban_grace_count_' . $hash, 0, \Config::get('seat.ban_grace'));
			return;

		} else {

			// Check if we have reached the limit for the allowed bad calls from the config
			if (\Cache::get('call_ban_grace_count_' . $hash) < \Config::get('seat.ban_limit') - 1) {

				// Add another one to the amount of failed calls and return
				\Cache::increment('call_ban_grace_count_' . $hash);
				return;
			}
		}

		\Log::warning('Ban limit reached. Actioning ban for api: ' . $api . ' scope: ' . $scope . ' owner: ' . $owner, array('src' => __CLASS__));

		// We _should_ only get here once the ban limit has been reached
		$banned = \EveBannedCall::where('hash', '=', $hash)->first();	
		if (!$banned)
			$banned = new \EveBannedCall;

		$banned->ownerID = $owner;
		$banned->api = $api;
		$banned->scope = $scope;
		$banned->accessMask = $accessMask;
		$banned->hash = $hash;
		$banned->reason = $reason;
		$banned->save();

	}

	/*
	|--------------------------------------------------------------------------
	| isBannedCall()
	|--------------------------------------------------------------------------
	|
	| Checks if a API call is banned
	|
	*/

	public static function isBannedCall($api, $scope, $owner = 0, $accessMask = 0)
	{

		// Check if we should retreive the current access mask
		if ($accessMask == 0)
			$accessMask = \EveAccountAPIKeyInfo::where('keyID', '=', $owner)->pluck('accessMask');

		$hash = BaseApi::makeCallHash($api, $scope, $owner . $accessMask);
		$banned = \EveBannedCall::where('hash', '=', $hash)->first();	

		if ($banned)
			return true;
		else
			return false;
	}

	/*
	|--------------------------------------------------------------------------
	| checkDbCache()
	|--------------------------------------------------------------------------
	|
	| Check if the data in the database is considered up to data.
	| Returns true if it is, false if it is not or there is no date recorded.
	|
	*/

	public static function checkDbCache($api, $scope, $cachedUntil, $owner = 0)
	{
		// Generate the hash based on the func args
		$hash = BaseApi::makeCallHash($api, $scope, $owner);
		$current_cache_time = $current_cache_time = \EveCachedUntil::where('hash', '=', $hash)->first();

		// Check if we have a cache timer set.
		if ($current_cache_time) {

			// If the timer is still the same as when we set it, return true
			// Else, return false indicating its no longer up to date.
			if ($current_cache_time->cached_until == $cachedUntil )
				return true;
			else
				return false;

		} else {

			// No cache timer means its not up to date and needs updatin
			return false;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| setDbCache()
	|--------------------------------------------------------------------------
	|
	| Set the cached_until time for a api call.
	|
	*/

	public static function setDbCache($api, $scope, $cachedUntil, $owner = 0)
	{
		// Generate the hash based on the func args
		$hash = BaseApi::makeCallHash($api, $scope, $owner);
		$cache_time_exists = $current_cache_time = \EveCachedUntil::where('hash', '=', $hash)->first();

		if ($current_cache_time) {

			$current_cache_time->cached_until = $cachedUntil;
			$current_cache_time->save();
		} else {

			\EveCachedUntil::create(array(
				'ownerID' => $owner,
				'api' => $api,
				'scope' => $scope,
				'hash' => $hash,
				'cached_until' => $cachedUntil
			));
		}
	}

	/*
	|--------------------------------------------------------------------------
	| lockCall()
	|--------------------------------------------------------------------------
	|
	| Set the cached_until time for a api call.
	|
	*/

	public static function lockCall($api, $scope, $owner = 0)
	{
		// Generate the hash based on the func args
		$hash = BaseApi::makeCallHash($api, $scope, $owner);

		\Cache::put('api_lock_' . $hash, '_locked_', Carbon::now()->addMinutes(60));

		return $hash;
	}

	/*
	|--------------------------------------------------------------------------
	| isLockedCall()
	|--------------------------------------------------------------------------
	|
	| Check if a API call is locked
	|
	*/

	public static function isLockedCall($api, $scope, $owner = 0)
	{
		// Generate the hash based on the func args
		$hash = BaseApi::makeCallHash($api, $scope, $owner);

		if (\Cache::has('api_lock_' . $hash)) {
			return true;
		}
		else {
			return false;
		}
	}

	/*
	|--------------------------------------------------------------------------
	| unlockCall()
	|--------------------------------------------------------------------------
	|
	| Unlocks a given api call via hash
	|
	*/

	public static function unlockCall($hash)
	{
		\Cache::forget('api_lock_' . $hash);
	}

	/*
	|--------------------------------------------------------------------------
	| determineAccess()
	|--------------------------------------------------------------------------
	|
	| Return an array of valid API calls that are allowed for a recorded keyID
	|
	*/

	public static function determineAccess($keyID)
	{

		// Locate the key in the db
		$key = \SeatKey::where('keyID', '=', $keyID)->where('isOk', '=', 1)->first();

		if (!$key)
			return array();

		// Attempt to get the type & accessMask from the database.
		Account\APIKeyInfo::update($keyID, $key->vCode);
		$key_mask_info = \EveAccountAPIKeyInfo::where('keyID', '=', $keyID)->first();

		// TODO: Where to put this call????
		Account\AccountStatus::update($keyID, $key->vCode);

		// If we still can't determine mask information, leave everything
		if (!$key_mask_info)
			return array();

		// Prepare a return by setting the 'type' key to the key type we have
		$type = ($key_mask_info->type == 'Account') ? 'Character' : $key_mask_info->type;
		$return_access = array('type' => $type);

		// Loop over all the masks we have, and return those we have access to for this key
		foreach (\EveApiCalllist::where('type', '=', $type)->get() as $mask) {
			
			if ($key_mask_info->accessMask & $mask->accessMask)
				$return_access['access'][] = array('type' => $mask->type, 'name' => $mask->name);
		}

		// Return it all as a nice array
		return $return_access;
	}

	/*
	|--------------------------------------------------------------------------
	| findKeyCharacters()
	|--------------------------------------------------------------------------
	|
	| Return an array of valid API calls that are allowed for a recorded keyID
	|
	*/

	public static function findKeyCharacters($keyID)
	{

		// Locate the key in the db
		$characters = \EveAccountAPIKeyInfoCharacters::where('keyID', '=', $keyID)->first();

		if (!$characters)
			return;

		$return = array();
		foreach (\EveAccountAPIKeyInfoCharacters::where('keyID', '=', $keyID)->get() as $character)
			$return[] = $character->characterID;

		// Return it all as a nice array
		return $return;
	}

	/*
	|--------------------------------------------------------------------------
	| findCharacterCorporation()
	|--------------------------------------------------------------------------
	|
	| Return the corporationID of a character
	|
	*/

	public static function findCharacterCorporation($characterID)
	{

		// Locate the key in the db
		$character = \EveAccountAPIKeyInfoCharacters::where('characterID', '=', $characterID)->first();

		if (!$character)
			return;

		// Return the characters corporationID
		return $character->corporationID;
	}

	/*
	|--------------------------------------------------------------------------
	| findClosestMoon()
	|--------------------------------------------------------------------------
	|
	| Find the moonID and name closest to the given coordinates
	|
	*/

	public static function findClosestMoon($itemID, $x, $y, $z)
	{

		// Get the system location of the itemID
		$systemID = \EveCorporationAssetList::where('itemID', '=', $itemID)->first();
		$nearest_distance = INF; // Placeholder amount

		// Prepare some empty responses
		$calculatedSystemID = null;
		$calculatedSystemName = null;

		// Find the closest moonID to $x, $x & $z. groupID 8 looks like moons only in the SDE
		foreach (\EveMapDenormalize::where('groupID', '=', 8)->where('solarSystemID', '=', $systemID->locationID)->get() as $system) {

			// So it looks there are 2 ways of determining the nearest celestial. The sqrt one is
			// aparently a lot more accurate, versus the manhattan one that can be seen in the ECM
			// source is slightly less accurate. TBH, I have no idea which one to use.

			// See: http://math.stackexchange.com/a/42642
			$distance = sqrt(pow(($x - $system->x), 2) + pow(($y - $system->y), 2) + pow(($z - $system->z), 2));

			// Or we can use this alternative from ECM: https://github.com/evecm/ecm/blob/master/ecm/plugins/assets/tasks/assets.py#L418
			// that uses http://en.wikipedia.org/wiki/Taxicab_geometry
			// $distance = abs($x - $system->x) + abs($y - $system->y) + abs($z - $system->z);

			// We are only interested in the moonID that is closest to our asset.
			// so will update to this moon if it is closer than the previous one
			if ($distance < $nearest_distance) {

				// Update the current closes distance for the next pass
				$nearest_distance = $distance;

				// Set the variables that will eventually be returned
				$calculatedSystemID = $system->itemID;
				$calculatedSystemName = $system->itemName;
			}
		}

		return array('id' => $calculatedSystemID, 'name' => $calculatedSystemName);
	}
}
