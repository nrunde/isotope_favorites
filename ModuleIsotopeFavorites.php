<?php if (!defined('TL_ROOT')) die('You can not access this file directly!');

/**
 * Contao Open Source CMS
 * Copyright (C) 2005-2010 Leo Feyer
 *
 * Formerly known as TYPOlight Open Source CMS.
 *
 * This program is free software: you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation, either
 * version 3 of the License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU
 * Lesser General Public License for more details.
 *
 * You should have received a copy of the GNU Lesser General Public
 * License along with this program. If not, please visit the Free
 * Software Foundation website at <http://www.gnu.org/licenses/>.
 *
 * PHP version 5
 * @copyright  Isotope eCommerce Workgroup 2009-2011
 * @author     Fred Bliss <fred.bliss@intelligentspark.com>
 * @author     Andreas Schempp <andreas@schempp.ch>
 * @license    http://opensource.org/licenses/lgpl-3.0.html
 */


class ModuleIsotopeFavorites extends ModuleIsotope
{

	/**
	 * Template
	 * @var string
	 */
	protected $strTemplate = 'mod_iso_favorites';

	/**
	 * Disable caching of the frontend page if this module is in use.
	 * @var bool
	 */
	protected $blnDisableCache = true;


	/**
	 * Display a wildcard in the back end
	 * @return string
	 */
	public function generate()
	{
		if (TL_MODE == 'BE')
		{
			$objTemplate = new BackendTemplate('be_wildcard');
			$objTemplate->wildcard = '### ISOTOPE ECOMMERCE: FAVORITES ###';
			$objTemplate->title = $this->headline;
			$objTemplate->id = $this->id;
			$objTemplate->link = $this->name;
			$objTemplate->href = 'contao/main.php?do=themes&amp;table=tl_module&amp;act=edit&amp;id=' . $this->id;

			return $objTemplate->parse();
		}

		$this->import('Isotope');
		$this->import('IsotopeFavorites');
		$this->IsotopeFavorites->initializeFavorites((int) $this->Isotope->Config->id, (int) $this->Isotope->Config->store_id);

		return parent::generate();
	}


	/**
	 * Generate module
	 */
	protected function compile()
	{
		// Surcharges must be initialized before getProducts() to apply tax_id to each product
		$arrSurcharges = $this->IsotopeFavorites->getSurcharges();
		foreach( $arrSurcharges as $k => $arrSurcharge )
		{
			$arrSurcharges[$k]['price']			= $this->Isotope->formatPriceWithCurrency($arrSurcharge['price']);
			$arrSurcharges[$k]['total_price']	= $this->Isotope->formatPriceWithCurrency($arrSurcharge['total_price']);
			$arrSurcharges[$k]['rowclass']		= trim('foot_'.($k+1) . ' ' . $arrSurcharge[$k]['rowclass']);
		}
		
		$arrProducts = $this->IsotopeFavorites->getProducts();

		if (!count($arrProducts))
		{
			$this->Template->empty = true;
			$this->Template->message = $GLOBALS['TL_LANG']['MSC']['noItemsInFavorites'];
			return;
		}

		$objTemplate = new IsotopeTemplate($this->iso_cart_layout);

		global $objPage;
		$strUrl = $this->generateFrontendUrl($objPage->row());

		$blnReload = false;
		$arrQuantity = $this->Input->post('quantity');
		$arrProductData = array();

		foreach( $arrProducts as $i => $objProduct )
		{
			// Remove product from favorites
			if ($this->Input->get('remove') == $objProduct->cart_id && $this->IsotopeFavorites->deleteProduct($objProduct))
			{
				$this->redirect((strlen($this->Input->get('referer')) ? base64_decode($this->Input->get('referer', true)) : $strUrl));
			}

			if ($this->Input->get('move') == $objProduct->cart_id)
			{
				$this->Isotope->Cart->addProduct($objProduct,$objProduct->quantity_requested);

				$this->redirect((strlen($this->Input->get('referer')) ? base64_decode($this->Input->get('referer', true)) : $strUrl));
			}
			
			// Update favorites data if form has been submitted
			elseif ($this->Input->post('FORM_SUBMIT') == ('iso_favorites_update_'.$this->id) && is_array($arrQuantity))
			{
				$blnReload = true;
				$this->IsotopeFavorites->updateProduct($objProduct, array('product_quantity'=>$arrQuantity[$objProduct->cart_id]));
				continue; // no need to generate $arrProductData, we reload anyway
			}

			// No need to generate product data if we reload anyway
			elseif ($blnReload)
			{
				continue;
			}

			$arrProductData[] = array_merge($objProduct->getAttributes(), array
			(
				'id'				=> $objProduct->id,
				'image'				=> $objProduct->images->main_image,
				'link'				=> $objProduct->href_reader,
				'original_price'	=> $this->Isotope->formatPriceWithCurrency($objProduct->original_price),
				'price'				=> $this->Isotope->formatPriceWithCurrency($objProduct->price),
				'total_price'		=> $this->Isotope->formatPriceWithCurrency($objProduct->total_price),
				'tax_id'			=> $objProduct->tax_id,
				'quantity'			=> $objProduct->quantity_requested,
				'cart_item_id'		=> $objProduct->cart_id,
				'product_options'	=> $objProduct->getOptions(),
				'move_link'			=> ampersand($strUrl . ($GLOBALS['TL_CONFIG']['disableAlias'] ? '&' : '?') . 'move='.$objProduct->cart_id.'&referer='.base64_encode($this->Environment->request)),
				'move_link_text'  	=> $GLOBALS['TL_LANG']['MSC']['moveProductLinkText'],
				'move_link_title' 	=> sprintf($GLOBALS['TL_LANG']['MSC']['moveProductLinkTitle'], $objProduct->name),				
				'remove_link'		=> ampersand($strUrl . ($GLOBALS['TL_CONFIG']['disableAlias'] ? '&' : '?') . 'remove='.$objProduct->cart_id.'&referer='.base64_encode($this->Environment->request)),
				'remove_link_text'  => $GLOBALS['TL_LANG']['MSC']['removeProductLinkText'],
				'remove_link_title' => sprintf($GLOBALS['TL_LANG']['MSC']['removeProductLinkTitle'], $objProduct->name),
				'class'				=> 'row_' . $i . ($i%2 ? ' even' : ' odd') . ($i==0 ? ' row_first' : ''),
			));
		}

		// Reload if the "checkout" button has been submitted and minimum order total is reached
		if ($blnReload && $this->Input->post('checkout') != '' && $this->iso_favorites_jumpTo)
		{
			$this->redirect($this->generateFrontendUrl($this->Database->execute("SELECT * FROM tl_page WHERE id={$this->iso_favorites_jumpTo}")->fetchAssoc()));
		}

		// Otherwise, just reload the page
		elseif ($blnReload)
		{
			$this->reload();
		}

		if (count($arrProductData))
		{
			$arrProductData[count($arrProductData)-1]['class'] .= ' row_last';
		}

		$objTemplate->hasError = false;
		$objTemplate->formId = 'iso_favorites_update_'.$this->id;
		$objTemplate->formSubmit = 'iso_favorites_update_'.$this->id;
		$objTemplate->summary = $GLOBALS['ISO_LANG']['MSC']['favoritesSummary'];
		$objTemplate->action = $this->Environment->request;
		$objTemplate->products = $arrProductData;
		$objTemplate->cartJumpTo = $this->iso_cart_jumpTo ? $this->generateFrontendUrl($this->Database->execute("SELECT * FROM tl_page WHERE id={$this->iso_cart_jumpTo}")->fetchAssoc()) : '';
		$objTemplate->cartLabel = $GLOBALS['TL_LANG']['MSC']['favoritesBT'];
		$objTemplate->checkoutJumpToLabel = $GLOBALS['TL_LANG']['MSC']['sendFavoritesBT'];
		$objTemplate->checkoutJumpTo = $this->iso_favorites_jumpTo ? $this->generateFrontendUrl($this->Database->execute("SELECT * FROM tl_page WHERE id={$this->iso_favorites_jumpTo}")->fetchAssoc()) : '';

		$objTemplate->subTotalLabel = $GLOBALS['TL_LANG']['MSC']['subTotalLabel'];
		$objTemplate->grandTotalLabel = $GLOBALS['TL_LANG']['MSC']['grandTotalLabel'];
		$objTemplate->subTotalPrice = $this->Isotope->formatPriceWithCurrency($this->IsotopeFavorites->subTotal);
		$objTemplate->grandTotalPrice = $this->Isotope->formatPriceWithCurrency($this->IsotopeFavorites->grandTotal);
		// @todo: make a module option.
		$objTemplate->showOptions = false;
		$objTemplate->surcharges = $arrSurcharges;

		$this->Template->empty = false;
		$this->Template->favorites = $objTemplate->parse();
		
	}
}

