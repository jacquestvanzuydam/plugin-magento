<?php
/**
 * Pargo CustomShipping
 *
 * @category    Pargo
 * @package     Pargo_CustomShipping
 * @copyright   Copyright (c) 2018 Pargo Points (https://pargo.co.za)
 * @license     http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 * @author     dev@pargo.co.za
 */

namespace Pargo\CustomShipping\Block;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Template\Context;

class PargoData extends \Magento\Framework\View\Element\Template
{
    public $scopeConfig;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Context $context
     * @param array $data
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        Context $context,
        array $data = []
    ) {
        $this->scopeConfig = $scopeConfig;

        parent::__construct($context, $data);
    }

    public function getContent()
    {
        return $this->scopeConfig->getValue(
            'carriers/pargo_customshipping/mapToken',
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
