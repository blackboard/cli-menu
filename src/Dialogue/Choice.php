<?php

 namespace PhpSchool\CliMenu\Dialogue;

use PhpSchool\Terminal\NonCanonicalReader;
use PhpSchool\Terminal\InputCharacter;

 /**
  * @author Aydin Hassan <aydin@hotmail.co.uk>
  */
 class Choice extends Dialogue
 {

     private $rightButtonTex    = 'Right';
     private $leftButtonText    = 'Left';
     private $rightButtonValue  = null;
     private $leftButtonValue   = null;

     private $optionValue = false;

     public function setLeftButton($text, $value)
     {
         $this->rightButtonText     = $text;
         $this->rightButtonValue    = $value;

         return $this;
     }

    public function setRightButton($text, $value)
     {
         $this->leftButtonText      = $text;
         $this->leftButtonValue     = $value;
         return $this;
     }
     public function getLeftButtonValue()
     {
         return $this->leftButtonValue;
     }
     public function getRightButtonValue()
     {
         return $this->rightButtonValue;
     }

     public function getLeftButtonText()
     {
         return sprintf(' %s ', $this->rightButtonText);
     }

     public function getRightButtonText()
     {
         return sprintf(' %s ', $this->leftButtonText);
     }

     public function setOptionValue($value)
     {
         $this->optionValue = $value;
         return $this;
     }


     private function getOptionValue()
     {
         return $this->optionValue;
     }

     private function displayBody()
     {
         $this->terminal->moveCursorToRow($this->y);
         $this->emptyRow();
         $this->write(sprintf(
             "%s%s%s%s%s\n",
             $this->style->getColoursSetCode(),
             str_repeat(' ', $this->style->getPaddingLeftRight()),
             $this->text,
             str_repeat(' ', $this->style->getPaddingLeftRight()),
             $this->style->getColoursResetCode()
         ));
         $this->emptyRow();

         $promptWidth = mb_strlen($this->text) + 4;
         $fillWidth = $promptWidth - (mb_strlen($this->getRightButtonText()) + mb_strlen($this->getLeftButtonText()));
         $placeHolderWidth = 0 == ($fillWidth % 2) ? 2 : 1;
         $fillWidth = ($fillWidth - $placeHolderWidth) / 2;

         $this->write(sprintf(
             '%s%s%s',
             $this->style->getColoursSetCode(),
             str_repeat(' ', $fillWidth),
             $this->style->getColoursResetCode()
         ));
         $rightColorCode    = $this->getOptionValue() == $this->getRightButtonValue() ? $this->style->getColoursSetCode() : $this->style->getColoursResetCode();
         $leftColorCode     = $this->getOptionValue() == $this->getLeftButtonValue()  ? $this->style->getColoursSetCode() : $this->style->getColoursResetCode();
         $this->write(
             sprintf(
                 '%s%s%s',
                 $rightColorCode,
                 $this->getRightButtonText(),
                 $this->getOptionValue() ? $this->style->getColoursSetCode() : $this->style->getColoursResetCode()
             ),
             -1
         );
         $this->write(
             sprintf(
                 '%s%s%s',
                 $this->style->getColoursSetCode(),
                 str_repeat(' ', $placeHolderWidth),
                 $this->style->getColoursResetCode()
             ),
             -1
         );

         $this->write(
             sprintf(
                 '%s%s%s',
                 $leftColorCode,
                 $this->getLeftButtonText(),
                 $this->getOptionValue() ? $this->style->getColoursResetCode() : $this->style->getColoursSetCode()
             ),
             -1
         );
         $this->write(sprintf(
             "%s%s%s\n",
             $this->style->getColoursSetCode(),
             str_repeat(' ', $fillWidth),
             $this->style->getColoursResetCode()
         ), -1);

         $this->write(sprintf(
             "%s%s%s%s%s\n",
             $this->style->getColoursSetCode(),
             str_repeat(' ', $this->style->getPaddingLeftRight()),
             str_repeat(' ', mb_strlen($this->text)),
             str_repeat(' ', $this->style->getPaddingLeftRight()),
             $this->style->getColoursResetCode()
         ));
         $this->terminal->moveCursorToTop();
     }

     /**
      *
      * The YesNO dialog box is displayed and the option value is passed to the callback function
      *
      * @param $callable
      */
     public function display($callable)
     {
        $this->assertMenuOpen();
        $this->displayBody();
        $reader = new NonCanonicalReader($this->terminal);

        while ($char = $reader->readCharacter()) {
            $ctl = $char->getControl();
            if ($char->isControl() && $char->getControl() === InputCharacter::ENTER) {
                $callable($this->getOptionValue());
                $this->parentMenu->redraw();
                return;
            }elseif ($char->isControl() && ( $char->getControl() === InputCharacter::RIGHT || $char->getControl() === InputCharacter::LEFT)) {
                 if($this->getOptionValue() == $this->getRightButtonValue()){
                     $this->setOptionValue($this->getLeftButtonValue());
                 }else{
                     $this->setOptionValue($this->getRightButtonValue());
                 }
                 $this->parentMenu->redraw();
                 $this->displayBody();
            }
        }
     }
 }