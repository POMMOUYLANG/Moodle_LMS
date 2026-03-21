<?php

class __Mustache_2f8ab59e52c3510b32d45cfceb0f073d extends Mustache_Template
{
    private $lambdaHelper;

    public function renderInternal(Mustache_Context $context, $indent = '')
    {
        $this->lambdaHelper = new Mustache_LambdaHelper($this->mustache, $context);
        $buffer = '';

        if ($parent = $this->mustache->loadPartial('core/notification_base')) {
            $context->pushBlockContext(array(
                'alertclass' => array($this, 'block68b4d9f1b5bacc23245e13158c6e456c'),
            ));
            $buffer .= $parent->renderInternal($context, $indent);
            $context->popBlockContext();
        }

        return $buffer;
    }


    public function block68b4d9f1b5bacc23245e13158c6e456c($context)
    {
        $indent = $buffer = '';
        $buffer .= $indent . 'alert-success';
    
        return $buffer;
    }
}
