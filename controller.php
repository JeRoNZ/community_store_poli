<?php

namespace Concrete\Package\CommunityStorePoli;

use Concrete\Package\CommunityStorePoli\Src\CommunityStore\Payment\Methods\CommunityStorePoli\CommunityStorePoliPaymentMethod;
use Package;
use Route;
use Whoops\Exception\ErrorException;
use Concrete\Package\CommunityStore\Src\CommunityStore\Payment\Method as PaymentMethod;

class Controller extends Package {
	protected $pkgHandle = 'community_store_poli';
	protected $appVersionRequired = '8.5.0';
	protected $pkgVersion = '0.2';

	protected $pkgAutoloaderRegistries = [
		'src/CommunityStore' => '\Concrete\Package\CommunityStorePoli\Src\CommunityStore'
	];

	public function getPackageDescription () {
		return t('POLi Payment Method for Community Store');
	}

	public function getPackageName () {
		return t('POLi Payment Method for Community Store');
	}

	public function install () {
		$installed = Package::getInstalledHandles();
		if (!(is_array($installed) && in_array('community_store', $installed))) {
			throw new ErrorException(t('This package requires that Community Store be installed'));
		} else {
			$pkg = parent::install();
			PaymentMethod::add('community_store_poli', 'POLi', $pkg);
		}

	}

	public function uninstall () {
		$pm = PaymentMethod::getByHandle('community_store_poli');
		if ($pm) {
			$pm->delete();
		}
		$pkg = parent::uninstall();
	}

	public function on_start () {
		Route::register('/checkout/polifail', CommunityStorePoliPaymentMethod::class . '::PoliFail');
		Route::register('/checkout/polinudge', CommunityStorePoliPaymentMethod::class . '::PoliNudge');
		Route::register('/checkout/polisuccess', CommunityStorePoliPaymentMethod::class . '::PoliSuccess');
	}
}