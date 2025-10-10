<?php

class __Mustache_871ba176068111ae3a76ec4eb93b4d4a extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core/notification_base')) {
            $context->pushBlockContext(array(
                'alertclass' => array($this, 'block70374e40d8af9fc27e052400cb31cbe8'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }

        return $buffer;
    }


    public function block70374e40d8af9fc27e052400cb31cbe8($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'alert-danger';
    
        return $buffer;
    }
}
