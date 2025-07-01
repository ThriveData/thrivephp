<?
	use ThriveData\ThrivePHP\{Route, Router};
	
	Router::register(new Route(url: '{^$}', callback: '\ui\start::routed'));
?>
