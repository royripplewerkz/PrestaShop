<?php
/*
* 2007-2016 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Open Software License (OSL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/osl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author PrestaShop SA <contact@prestashop.com>
*  @copyright  2007-2016 PrestaShop SA
*  @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*/

abstract class Module extends ModuleCore
{
    /**
     * Return available modules
     *
     * @param bool $use_config in order to use config.xml file in module dir
     * @return array Modules
     */
    public static function getModulesOnDisk($use_config = false, $logged_on_addons = false, $id_employee = false)
    {
        global $_MODULES;

        // Init var
        $module_list = array();
        $module_name_list = array();
        $modules_name_to_cursor = array();
        $errors = array();

        // Get modules directory list and memory limit
        $modules_dir = Module::getModulesDirOnDisk();

        $modules_installed = array();
        $result = Db::getInstance()->executeS('
		SELECT m.name, m.version, mp.interest, module_shop.enable_device
		FROM `'._DB_PREFIX_.'module` m
		'.Shop::addSqlAssociation('module', 'm').'
		LEFT JOIN `'._DB_PREFIX_.'module_preference` mp ON (mp.`module` = m.`name` AND mp.`id_employee` = '.(int)$id_employee.')');
        foreach ($result as $row) {
            $modules_installed[$row['name']] = $row;
        }

        foreach ($modules_dir as $module) {
            if (Module::useTooMuchMemory()) {
                $errors[] = Tools::displayError('All modules cannot be loaded due to memory limit restrictions, please increase your memory_limit value on your server configuration');
                break;
            }

            $iso = substr(Context::getContext()->language->iso_code, 0, 2);

            // Check if config.xml module file exists and if it's not outdated

            if ($iso == 'en') {
                $config_file = _PS_MODULE_DIR_.$module.'/config.xml';
            } else {
                $config_file = _PS_MODULE_DIR_.$module.'/config_'.$iso.'.xml';
            }

            $xml_exist = (file_exists($config_file));
            $need_new_config_file = $xml_exist ? (@filemtime($config_file) < @filemtime(_PS_MODULE_DIR_.$module.'/'.$module.'.php')) : true;

            // If config.xml exists and that the use config flag is at true
            if ($use_config && $xml_exist && !$need_new_config_file) {
                // Load config.xml
                libxml_use_internal_errors(true);
                $xml_module = @simplexml_load_file($config_file);
                if (!$xml_module) {
                    $errors[] = Tools::displayError(sprintf('%1s could not be loaded.', $config_file));
                    break;
                }
                foreach (libxml_get_errors() as $error) {
                    $errors[] = '['.$module.'] '.Tools::displayError('Error found in config file:').' '.htmlentities($error->message);
                }
                libxml_clear_errors();

                // If no errors in Xml, no need instand and no need new config.xml file, we load only translations
                if (!count($errors) && (int)$xml_module->need_instance == 0) {
                    $file = _PS_MODULE_DIR_.$module.'/'.Context::getContext()->language->iso_code.'.php';
                    if (Tools::file_exists_cache($file) && include_once($file)) {
                        if (isset($_MODULE) && is_array($_MODULE)) {
                            $_MODULES = !empty($_MODULES) ? array_merge($_MODULES, $_MODULE) : $_MODULE;
                        }
                    }

                    $item = new stdClass();
                    $item->id = 0;
                    $item->warning = '';

                    foreach ($xml_module as $k => $v) {
                        $item->$k = (string)$v;
                    }

                    $item->displayName = stripslashes(Translate::getModuleTranslation((string)$xml_module->name, Module::configXmlStringFormat($xml_module->displayName), (string)$xml_module->name));
                    $item->description = stripslashes(Translate::getModuleTranslation((string)$xml_module->name, Module::configXmlStringFormat($xml_module->description), (string)$xml_module->name));
                    $item->author = stripslashes(Translate::getModuleTranslation((string)$xml_module->name, Module::configXmlStringFormat($xml_module->author), (string)$xml_module->name));
                    $item->author_uri = (isset($xml_module->author_uri) && $xml_module->author_uri) ? stripslashes($xml_module->author_uri) : false;

                    if (isset($xml_module->confirmUninstall)) {
                        $item->confirmUninstall = Translate::getModuleTranslation((string)$xml_module->name, html_entity_decode(Module::configXmlStringFormat($xml_module->confirmUninstall)), (string)$xml_module->name);
                    }

                    $item->active = 0;
                    $item->onclick_option = false;
                    $item->trusted = Module::isModuleTrusted($item->name);

                    $module_list[] = $item;

                    $module_name_list[] = '\''.pSQL($item->name).'\'';
                    $modules_name_to_cursor[Tools::strtolower(strval($item->name))] = $item;
                }
            }

            // If use config flag is at false or config.xml does not exist OR need instance OR need a new config.xml file
            if (!$use_config || !$xml_exist || (isset($xml_module->need_instance) && (int)$xml_module->need_instance == 1) || $need_new_config_file) {
                // If class does not exists, we include the file
                if (!class_exists($module, false)) {
                    // Get content from php file
                    $file_path = _PS_MODULE_DIR_.$module.'/'.$module.'.php';
                    $file = trim(file_get_contents(_PS_MODULE_DIR_.$module.'/'.$module.'.php'));

                    if (substr($file, 0, 5) == '<?php') {
                        $file = substr($file, 5);
                    }

                    if (substr($file, -2) == '?>') {
                        $file = substr($file, 0, -2);
                    }

                    // If (false) is a trick to not load the class with "eval".
                    // This way require_once will works correctly
                    if (eval('if (false){	'.$file."\n".' }') !== false) {
                        require_once(_PS_MODULE_DIR_.$module.'/'.$module.'.php');
                    } else {
                        $errors[] = sprintf(Tools::displayError('%1$s (parse error in %2$s)'), $module, substr($file_path, strlen(_PS_ROOT_DIR_)));
                    }
                }

                // If class exists, we just instanciate it
                if (class_exists($module, false)) {
                    $tmp_module = Adapter_ServiceLocator::get($module);

                    $item = new stdClass();
                    $item->id = $tmp_module->id;
                    $item->warning = $tmp_module->warning;
                    $item->name = $tmp_module->name;
                    $item->version = $tmp_module->version;
                    $item->tab = $tmp_module->tab;
                    $item->displayName = $tmp_module->displayName;
                    $item->description = stripslashes($tmp_module->description);
                    $item->author = $tmp_module->author;
                    $item->author_uri = (isset($tmp_module->author_uri) && $tmp_module->author_uri) ? $tmp_module->author_uri : false;
                    $item->limited_countries = $tmp_module->limited_countries;
                    $item->parent_class = get_parent_class($module);
                    $item->is_configurable = $tmp_module->is_configurable = method_exists($tmp_module, 'getContent') ? 1 : 0;
                    $item->need_instance = isset($tmp_module->need_instance) ? $tmp_module->need_instance : 0;
                    $item->active = $tmp_module->active;
                    $item->trusted = Module::isModuleTrusted($tmp_module->name);
                    $item->currencies = isset($tmp_module->currencies) ? $tmp_module->currencies : null;
                    $item->currencies_mode = isset($tmp_module->currencies_mode) ? $tmp_module->currencies_mode : null;
                    $item->confirmUninstall = isset($tmp_module->confirmUninstall) ? html_entity_decode($tmp_module->confirmUninstall) : null;
                    $item->description_full = stripslashes($tmp_module->description_full);
                    $item->additional_description = isset($tmp_module->additional_description) ? stripslashes($tmp_module->additional_description) : null;
                    $item->compatibility = isset($tmp_module->compatibility) ? (array)$tmp_module->compatibility : null;
                    $item->nb_rates = isset($tmp_module->nb_rates) ? (array)$tmp_module->nb_rates : null;
                    $item->avg_rate = isset($tmp_module->avg_rate) ? (array)$tmp_module->avg_rate : null;
                    $item->badges = isset($tmp_module->badges) ? (array)$tmp_module->badges : null;
                    $item->url = isset($tmp_module->url) ? $tmp_module->url : null;
                    $item->onclick_option  = method_exists($module, 'onclickOption') ? true : false;

                    if ($item->onclick_option) {
                        $href = Context::getContext()->link->getAdminLink('Module', true).'&module_name='.$tmp_module->name.'&tab_module='.$tmp_module->tab;
                        $item->onclick_option_content = array();
                        $option_tab = array('desactive', 'reset', 'configure', 'delete');

                        foreach ($option_tab as $opt) {
                            $item->onclick_option_content[$opt] = $tmp_module->onclickOption($opt, $href);
                        }
                    }

                    $module_list[] = $item;

                    if (!$xml_exist || $need_new_config_file) {
                        self::$_generate_config_xml_mode = true;
                        $tmp_module->_generateConfigXml();
                        self::$_generate_config_xml_mode = false;
                    }

                    unset($tmp_module);
                } else {
                    $errors[] = sprintf(Tools::displayError('%1$s (class missing in %2$s)'), $module, substr($file_path, strlen(_PS_ROOT_DIR_)));
                }
            }
        }

        // Get modules information from database
        if (!empty($module_name_list)) {
            $list = Shop::getContextListShopID();
            $sql = 'SELECT m.id_module, m.name, (
						SELECT COUNT(*) FROM '._DB_PREFIX_.'module_shop ms WHERE m.id_module = ms.id_module AND ms.id_shop IN ('.implode(',', $list).')
					) as total
					FROM '._DB_PREFIX_.'module m
					WHERE LOWER(m.name) IN ('.Tools::strtolower(implode(',', $module_name_list)).')';
            $results = Db::getInstance()->executeS($sql);

            foreach ($results as $result) {
                if (isset($modules_name_to_cursor[Tools::strtolower($result['name'])])) {
                    $module_cursor = $modules_name_to_cursor[Tools::strtolower($result['name'])];
                    $module_cursor->id = (int)$result['id_module'];
                    $module_cursor->active = ($result['total'] == count($list)) ? 1 : 0;
                }
            }
        }

        // Get Default Country Modules and customer module
        $files_list = array(
            // array('type' => 'addonsNative', 'file' => _PS_ROOT_DIR_.self::CACHE_FILE_DEFAULT_COUNTRY_MODULES_LIST, 'loggedOnAddons' => 0),
            // array('type' => 'addonsMustHave', 'file' => _PS_ROOT_DIR_.self::CACHE_FILE_MUST_HAVE_MODULES_LIST, 'loggedOnAddons' => 0),
            array('type' => 'addonsBought', 'file' => _PS_ROOT_DIR_.self::CACHE_FILE_CUSTOMER_MODULES_LIST, 'loggedOnAddons' => 1),
        );
        foreach ($files_list as $f) {
            if (file_exists($f['file']) && ($f['loggedOnAddons'] == 0 || $logged_on_addons)) {
                if (Module::useTooMuchMemory()) {
                    $errors[] = Tools::displayError('All modules cannot be loaded due to memory limit restrictions, please increase your memory_limit value on your server configuration');
                    break;
                }

                $file = $f['file'];
                $content = Tools::file_get_contents($file);
                $xml = @simplexml_load_string($content, null, LIBXML_NOCDATA);

                if ($xml && isset($xml->module)) {
                    foreach ($xml->module as $modaddons) {
                        $flag_found = 0;

                        foreach ($module_list as $k => &$m) {
                            if (Tools::strtolower($m->name) == Tools::strtolower($modaddons->name) && !isset($m->available_on_addons)) {
                                $flag_found = 1;
                                if ($m->version != $modaddons->version && version_compare($m->version, $modaddons->version) === -1) {
                                    $module_list[$k]->version_addons = $modaddons->version;
                                }
                            }
                        }

                        if ($flag_found == 0) {
                            $item = new stdClass();
                            $item->id = 0;
                            $item->warning = '';
                            $item->type = strip_tags((string)$f['type']);
                            $item->name = strip_tags((string)$modaddons->name);
                            $item->version = strip_tags((string)$modaddons->version);
                            $item->tab = strip_tags((string)$modaddons->tab);
                            $item->displayName = strip_tags((string)$modaddons->displayName);
                            $item->description = stripslashes(strip_tags((string)$modaddons->description));
                            $item->description_full = stripslashes(strip_tags((string)$modaddons->description_full));
                            $item->author = strip_tags((string)$modaddons->author);
                            $item->limited_countries = array();
                            $item->parent_class = '';
                            $item->onclick_option = false;
                            $item->is_configurable = 0;
                            $item->need_instance = 0;
                            $item->not_on_disk = 1;
                            $item->available_on_addons = 1;
                            $item->trusted = Module::isModuleTrusted($item->name);
                            $item->active = 0;
                            $item->description_full = stripslashes($modaddons->description_full);
                            $item->additional_description = isset($modaddons->additional_description) ? stripslashes($modaddons->additional_description) : null;
                            $item->compatibility = isset($modaddons->compatibility) ? (array)$modaddons->compatibility : null;
                            $item->nb_rates = isset($modaddons->nb_rates) ? (array)$modaddons->nb_rates : null;
                            $item->avg_rate = isset($modaddons->avg_rate) ? (array)$modaddons->avg_rate : null;
                            $item->badges = isset($modaddons->badges) ? (array)$modaddons->badges : null;
                            $item->url = isset($modaddons->url) ? $modaddons->url : null;

                            if (isset($modaddons->img)) {
                                if (!file_exists(_PS_TMP_IMG_DIR_.md5((int)$modaddons->id.'-'.$modaddons->name).'.jpg')) {
                                    if (!file_put_contents(_PS_TMP_IMG_DIR_.md5((int)$modaddons->id.'-'.$modaddons->name).'.jpg', Tools::file_get_contents($modaddons->img))) {
                                        copy(_PS_IMG_DIR_.'404.gif', _PS_TMP_IMG_DIR_.md5((int)$modaddons->id.'-'.$modaddons->name).'.jpg');
                                    }
                                }

                                if (file_exists(_PS_TMP_IMG_DIR_.md5((int)$modaddons->id.'-'.$modaddons->name).'.jpg')) {
                                    $item->image = '../img/tmp/'.md5((int)$modaddons->id.'-'.$modaddons->name).'.jpg';
                                }
                            }

                            if ($item->type == 'addonsMustHave') {
                                $item->addons_buy_url = strip_tags((string)$modaddons->url);
                                $prices = (array)$modaddons->price;
                                $id_default_currency = Configuration::get('PS_CURRENCY_DEFAULT');

                                foreach ($prices as $currency => $price) {
                                    if ($id_currency = Currency::getIdByIsoCode($currency)) {
                                        $item->price = (float)$price;
                                        $item->id_currency = (int)$id_currency;

                                        if ($id_default_currency == $id_currency) {
                                            break;
                                        }
                                    }
                                }
                            }

                            $module_list[$modaddons->id.'-'.$item->name] = $item;
                        }
                    }
                }
            }
        }

        foreach ($module_list as $key => &$module) {
            if (defined('_PS_HOST_MODE_') && in_array($module->name, self::$hosted_modules_blacklist)) {
                unset($module_list[$key]);
            } elseif (isset($modules_installed[$module->name])) {
                $module->installed = true;
                $module->database_version = $modules_installed[$module->name]['version'];
                $module->interest = $modules_installed[$module->name]['interest'];
                $module->enable_device = $modules_installed[$module->name]['enable_device'];
            } else {
                $module->installed = false;
                $module->database_version = 0;
                $module->interest = 0;
            }
        }

        usort($module_list, create_function('$a,$b', 'return strnatcasecmp($a->displayName, $b->displayName);'));
        if ($errors) {
            if (!isset(Context::getContext()->controller) && !Context::getContext()->controller->controller_name) {
                echo '<div class="alert error"><h3>'.Tools::displayError('The following module(s) could not be loaded').':</h3><ol>';
                foreach ($errors as $error) {
                    echo '<li>'.$error.'</li>';
                }
                echo '</ol></div>';
            } else {
                foreach ($errors as $error) {
                    Context::getContext()->controller->errors[] = $error;
                }
            }
        }

        return $module_list;
    }
}
