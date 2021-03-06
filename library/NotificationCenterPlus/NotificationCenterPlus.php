<?php

namespace HeimrichHannot\NotificationCenterPlus;

use HeimrichHannot\Haste\Util\Arrays;
use HeimrichHannot\Haste\Util\Files;
use HeimrichHannot\Haste\Util\Salutations;
use HeimrichHannot\Haste\Util\Url;
use NotificationCenter\Model\Message;
use NotificationCenter\Model\Notification;

class NotificationCenterPlus
{
	const CSS_MODE_INLINE = 'inline';
	const CSS_MODE_HEADER = 'header';

	public static function addHeaderCss($strText, Message $objMessage)
	{
		$arrHeaderStylesheetContents =
			static::getStylesheetContents($objMessage, static::CSS_MODE_HEADER);

		if (!empty($arrHeaderStylesheetContents))
		{
			$doc = \phpQuery::newDocumentHTML($strText);

			pq('html > head')->append(sprintf('<style type="text/css">%s</style>', implode(' ',
				$arrHeaderStylesheetContents)));

			return $doc->htmlOuter();
		}

		return $strText;
	}

	// some mail clients can't handle margin'ed p elements :-(
	public static function convertPToBr($strText)
	{
		return preg_replace('@</p>[\n\r\s]*<p>@i', '<br><br>', $strText);
	}

	public static function getStylesheetPaths(Message $objMessage, $strMode)
	{
		$arrStylesheets = deserialize($strMode == static::CSS_MODE_INLINE ?
			$objMessage->inlineStylesheets : $objMessage->headerStylesheets, true);

		if (!empty($arrStylesheets))
		{
			$arrStylesheetPaths = array_map(function($strUuid) {
				$strPath = TL_ROOT . '/' . Files::getPathFromUuid($strUuid);

				return (file_exists($strPath) ? $strPath : '');
			}, $arrStylesheets);

			// remove non-found stylesheets
			return array_filter($arrStylesheetPaths);
		}

		return [];
	}

	public static function getStylesheetContents(Message $objMessage, $strMode)
	{
		return array_map('file_get_contents', static::getStylesheetPaths($objMessage, $strMode));
	}

	public function addTokens($objMessage, &$arrTokens, $strLanguage, $objGatewayModel)
	{
		if (!isset($arrTokens['salutation_user']))
			$arrTokens['salutation_user'] = Salutations::createSalutation($strLanguage, \FrontendUser::getInstance());

		if (!isset($arrTokens['salutation_form']))
			$arrTokens['salutation_form'] = Salutations::createSalutation($strLanguage, [
				'gender' => $arrTokens['form_value_gender'],
				'title' => $arrTokens['form_title'] ?: $arrTokens['form_academicTitle'],
				'lastname' => $arrTokens['form_lastname']
            ]
            );

		if (in_array('isotope', \ModuleLoader::getActive()))
		{
			if (!isset($arrTokens['billing_address_form']))
				$arrTokens['salutation_billing_address'] = Salutations::createSalutation($strLanguage, [
					'gender' => $arrTokens['billing_address_gender'],
					'title' => $arrTokens['billing_address_title'],
					'lastname' => $arrTokens['billing_address_lastname']
                ]
                );
		}

		$this->addContextTokens($objMessage, $arrTokens, $strLanguage);
		
		return true;
	}

	public static function getTokensFromEntity($objEntity, $strPrefix, $arrFields = [])
	{
		$arrTokens = [];

		foreach ($objEntity->row() as $strKey => $varValue)
		{
			if (empty($arrFields) || in_array($strKey, $arrFields))
			{
				$arrTokens[$strPrefix . '_' . lcfirst($strKey)] = $varValue;
			}
		}

		return $arrTokens;
	}

	/**
	 *
	 * Add contao core tokens, as long as the cron job does not have these information
	 * on sending mail in queue mode
	 *
	 * @param $arrTokens
	 * @param $strLanguage
	 * @return bool false if context_tokens has been set already (required by cron)
	 */
	protected function addContextTokens($objMessage, &$arrTokens, $strLanguage)
	{
		// add context tokens only once (queue will trigger this function again, and tokens might be overwritten)
		if(isset($arrTokens['context_tokens']))
		{
			return false;
		}

		$arrTokens['context_tokens'] = true;

		// add environment variables as token
		$arrTokens['env_host'] = \Idna::decode(\Environment::get('host'));
		$arrTokens['env_http_host'] = \Idna::decode(\Environment::get('httpHost'));
		$arrTokens['env_url'] = \Idna::decode(\Environment::get('url'));
		$arrTokens['env_path'] = \Idna::decode(\Environment::get('base'));
		$arrTokens['env_request'] = \Idna::decode(\Environment::get('indexFreeRequest'));
        $arrTokens['env_request_path'] = \Idna::decode(Url::removeAllParametersFromUri(\Environment::get('indexFreeRequest')));
		$arrTokens['env_ip'] = \Idna::decode(\Environment::get('ip'));
		$arrTokens['env_referer'] = \System::getReferer();
		$arrTokens['env_files_url'] = TL_FILES_URL;
		$arrTokens['env_plugins_url'] = TL_ASSETS_URL;
		$arrTokens['env_script_url'] = TL_ASSETS_URL;


		// add date tokens
		$arrTokens['date'] = \Controller::replaceInsertTags(sprintf('{{date::%s}}', \Config::get('dateFormat')));
		$arrTokens['last_update'] = \Controller::replaceInsertTags('{{last_update}}');

		if(TL_MODE == 'FE')
		{
			// add current page as token
			global $objPage;

			if($objPage !== null)
			{
				foreach($objPage->row() as $key => $value)
				{
					$arrTokens['page_' . $key] = $value;
				}

				if($objPage->pageTitle == '')
				{
					$arrTokens['pageTitle'] = $objPage->title;
				}
				else if($objPage->parentPageTitle == '')
				{
					$arrTokens['parentPageTitle'] = $objPage->parentTitle;
				}
				else if($objPage->mainPageTitle == '')
				{
					$arrTokens['mainPageTitle'] = $objPage->mainTitle;
				}
			}

			// add user attributes as token
			if(FE_USER_LOGGED_IN)
			{
				$arrUserData = \FrontendUser::getInstance()->getData();

				if(is_array($arrUserData))
				{
					foreach($arrUserData as $key => $value)
					{
						if(!is_array($value) && \Validator::isBinaryUuid($value))
						{
							$value = \StringUtil::binToUuid($value);

							$objFile = \FilesModel::findByUuid($value);

							if($objFile !== null)
							{
								$value = $objFile->path;
							}
						}
						
						$arrTokens['user_' . $key] = $value;
					}
				}
			}
		}
	}

	public static function sendNotification($intId, $arrTokens)
	{
		if (($objNotification = Notification::findByPk($intId)) !== null)
		{
			$objNotification->send($arrTokens, $GLOBALS['TL_LANGUAGE']);
		}
	}

	public static function getNotificationsAsOptions($strType)
	{
		$arrChoices = [];
		$objNotifications = \Database::getInstance()->execute("SELECT id,title FROM tl_nc_notification WHERE type='$strType' ORDER BY title");

		while ($objNotifications->next()) {
			$arrChoices[$objNotifications->id] = $objNotifications->title;
		}

		return $arrChoices;
	}

	public function getNotificationMessagesAsOptions()
	{
		$arrOptions = [];

		if (($objMessages = Message::findAll()) === null) {
			return $arrOptions;
		}

		while ($objMessages->next())
		{
			if (($objNotification = $objMessages->getRelated('pid')) === null) {
				continue;
			}

			$arrOptions[$objNotification->title][$objMessages->id] = $objMessages->title;
		}

		return $arrOptions;
	}
}
