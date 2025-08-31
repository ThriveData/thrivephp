<?
	namespace ThriveData\ThrivePHP;
	
	define('PATH_ROOT', realpath(__DIR__).'/..');
	
	require_once(PATH_ROOT.'/vendor/autoload.php');
	
	Application::init();
	Application::start();

?>
