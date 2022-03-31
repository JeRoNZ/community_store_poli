<?php
defined('C5_EXECUTE') or die(_('Access Denied.'));
extract($vars);
?>

<div class="form-group">
	<?= $form->label('policurrency', t('Currency')); ?>
	<?= $form->select('policurrency', $currencies, $currency ? $currency : 'NZD'); ?>
</div>

<div class="form-group">
	<?= $form->label('politestapiurl', t('Test API URL')); ?>
	<?= $form->text('politestapiurl', ($testapiurl ? $testapiurl : 'https://poliapi.uat1.paywithpoli.com/')) ?>
</div>

<div class="form-group">
	<?= $form->label('politestmerchantcode', t('Test Merchant Code')); ?>
	<?= $form->text('politestmerchantcode', $testmerchantcode) ?>
</div>

<div class="form-group">
	<?= $form->label('politestauthcode', t('Test Authentication Code')); ?>
	<?= $form->text('politestauthcode', $testauthcode) ?>
</div>


<div class="form-group">
	<?= $form->checkbox('politestmode', '1', $testmode) ?>
	<?= $form->label('politestmode', t('Test Mode')) ?>
</div>

<div class="form-group">
	<?= $form->label('poliliveapiurl', t('Live API URL')); ?>
	<?= $form->text('poliliveapiurl', ($liveapiurl ? $liveapiurl : 'https://poliapi.apac.paywithpoli.com/')) ?>
</div>
<div class="form-group">
	<?= $form->label('polilivemerchantcode', t('Live Merchant Code')); ?>
	<?= $form->text('polilivemerchantcode', $livemerchantcode) ?>
</div>
<div class="form-group">
	<?= $form->label('poliliveauthcode', t('Live Authentication Code')); ?>
	<?= $form->text('poliliveauthcode', $liveauthcode) ?>
</div>

<div class="form-group">
	<?= $form->label('polimaxattempts', t('Maximum checkout attempts')); ?>
	<?= $form->number('polimaxattempts', ($maxattempts ?: 20), ['min' => 5, 'max' => 100, 'step' => '1']) ?>
</div>


<div class="form-group">
	<?= $form->checkbox('polidebug', '1', $debug) ?>
	<?= $form->label('polidebug', t('Debug logging')) ?>
</div>