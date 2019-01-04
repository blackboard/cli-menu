<?php

namespace PhpSchool\CliMenu;

use PhpSchool\CliMenu\Exception\InvalidTerminalException;
use PhpSchool\CliMenu\Exception\MenuNotOpenException;
use PhpSchool\CliMenu\Input\InputIO;
use PhpSchool\CliMenu\Input\Number;
use PhpSchool\CliMenu\Input\Password;
use PhpSchool\CliMenu\Input\Text;
use PhpSchool\CliMenu\MenuItem\LineBreakItem;
use PhpSchool\CliMenu\MenuItem\MenuItemInterface;
use PhpSchool\CliMenu\MenuItem\SplitItem;
use PhpSchool\CliMenu\MenuItem\StaticItem;
use PhpSchool\CliMenu\Dialogue\Confirm;
use PhpSchool\CliMenu\Dialogue\Flash;
use PhpSchool\CliMenu\Dialogue\Choice;
use PhpSchool\CliMenu\Terminal\TerminalFactory;
use PhpSchool\CliMenu\Util\StringUtil as s;
use PhpSchool\Terminal\InputCharacter;
use PhpSchool\Terminal\NonCanonicalReader;
use PhpSchool\Terminal\Terminal;

/**
 * @author Michael Woodward <mikeymike.mw@gmail.com>
 */
class CliMenu
{
    /**
     * @var Terminal
     */
    protected $terminal;

    /**
     * @var MenuStyle
     */
    protected $style;

    /**
     * @var ?string
     */
    protected $title;

    /**
     * @var ?int
     */
    protected $requiredTerminalWidth = null;


    /**
     * @var MenuItemInterface[]
     */
    protected $items = [];

    /**
     * @var int|null
     */
    protected $selectedItem;

    /**
     * @var bool
     */
    protected $open = false;

    /**
     * @var CliMenu|null
     */
    protected $parent;

    /**
     * @var array
     */
    protected $defaultControlMappings = [
        '^P' => InputCharacter::UP,
        'k'  => InputCharacter::UP,
        '^K' => InputCharacter::DOWN,
        'j'  => InputCharacter::DOWN,
        "\r" => InputCharacter::ENTER,
        ' '  => InputCharacter::ENTER,
        'l'  => InputCharacter::LEFT,
        'm'  => InputCharacter::RIGHT,
    ];

    /**
     * @var array
     */
    protected $customControlMappings = [];

    /**
     * @var Frame
     */
    private $currentFrame;

    public function __construct(
        ?string $title,
        array $items,
        Terminal $terminal = null,
        MenuStyle $style = null
    ) {
        $this->title      = $title;
        $this->items      = $items;
        $this->terminal   = $terminal ?: TerminalFactory::fromSystem();
        $this->style      = $style ?: new MenuStyle($this->terminal);

        $this->selectFirstItem();
    }

    /**
     * Configure the terminal to work with CliMenu
     */
    protected function configureTerminal() : void
    {
        $this->assertTerminalIsValidTTY();

        $this->terminal->disableCanonicalMode();
        $this->terminal->disableEchoBack();
        $this->terminal->disableCursor();
        $this->terminal->clear();
    }

    /**
     * Revert changes made to the terminal
     */
    protected function tearDownTerminal() : void
    {
        $this->terminal->restoreOriginalConfiguration();
        $this->terminal->enableCursor();
    }

    /**
     * @return mixed
     */
    public function getRequiredTerminalWidth(): ?int
    {
        return $this->requiredTerminalWidth;
    }

    /**
     * @param mixed $requiredTerminalWidth
     */
    public function setRequiredTerminalWidth($requiredTerminalWidth): void
    {
        $this->requiredTerminalWidth = $requiredTerminalWidth;
    }


    private function assertTerminalIsValidTTY() : void
    {
        if (!$this->terminal->isInteractive()) {
            throw new InvalidTerminalException('Terminal is not interactive (TTY)');
        }
    }

    public function setTitle(string $title) : void
    {
        $this->title = $title;
    }

    public function getTitle() : string
    {
        return $this->title;
    }

    public function setParent(CliMenu $parent) : void
    {
        $this->parent = $parent;
    }

    public function getParent() : ?CliMenu
    {
        return $this->parent;
    }

    public function getTerminal() : Terminal
    {
        return $this->terminal;
    }

    public function isOpen() : bool
    {
        return $this->open;
    }

    /**
     * Add a new Item to the menu
     */
    public function addItem(MenuItemInterface $item) : void
    {
        $this->items[] = $item;

        $this->selectFirstItem();
    }

    /**
     * Add multiple Items to the menu
     */
    public function addItems(array $items) : void
    {
        foreach ($items as $item) {
            $this->items[] = $item;
        }

        $this->selectFirstItem();
    }

    /**
     * Set Items of the menu
     */
    public function setItems(array $items) : void
    {
        $this->selectedItem = null;
        $this->items = $items;

        $this->selectFirstItem();
    }

    /**
     * Set the selected pointer to the first selectable item
     */
    private function selectFirstItem() : void
    {
        if (null === $this->selectedItem) {
            foreach ($this->items as $key => $item) {
                if ($item->canSelect()) {
                    $this->selectedItem = $key;
                    break;
                }
            }
        }
    }

    /**
     * Adds a custom control mapping
     */
    public function addCustomControlMapping(string $input, callable $callable) : void
    {
        if (isset($this->defaultControlMappings[$input]) || isset($this->customControlMappings[$input])) {
            throw new \InvalidArgumentException('Cannot rebind this input');
        }

        $this->customControlMappings[$input] = $callable;
    }

    /**
     * Shorthand function to add multiple custom control mapping at once
     */
    public function addCustomControlMappings(array $map) : void
    {
        foreach ($map as $input => $callable) {
            $this->addCustomControlMapping($input, $callable);
        }
    }

    /**
     * Removes a custom control mapping
     */
    public function removeCustomControlMapping(string $input) : void
    {
        if (!isset($this->customControlMappings[$input])) {
            throw new \InvalidArgumentException('This input is not registered');
        }

        unset($this->customControlMappings[$input]);
    }

    /**
     * Display menu and capture input
     */
    private function display() : void
    {
        $this->draw();

        $reader = new NonCanonicalReader($this->terminal);
        $reader->addControlMappings($this->defaultControlMappings);

        while ($this->isOpen() && $char = $reader->readCharacter()) {
            if (!$char->isHandledControl()) {
                $rawChar = $char->get();
                if (isset($this->customControlMappings[$rawChar])) {
                    $this->customControlMappings[$rawChar]($this);
                }
                continue;
            }

            switch ($char->getControl()) {
                case InputCharacter::UP:
                case InputCharacter::DOWN:
                    $this->moveSelectionVertically($char->getControl());
                    $this->draw();
                    break;
                case InputCharacter::LEFT:
                case InputCharacter::RIGHT:
                    $this->moveSelectionHorizontally($char->getControl());
                    $this->draw();
                    break;
                case InputCharacter::ENTER:
                    $this->executeCurrentItem();
                    break;
            }
        }
    }

    /**
     * Move the selection in a given direction, up / down
     */
    protected function moveSelectionVertically(string $direction) : void
    {
        $itemKeys = array_keys($this->items);

        $increments = 0;

        do {
            $increments++;

            if ($increments > count($itemKeys)) {
                //full cycle detected, there must be no selected items
                //in the menu, so stop trying to select one.
                return;
            }

            $direction === 'UP'
                ? $this->selectedItem--
                : $this->selectedItem++;

            if (!array_key_exists($this->selectedItem, $this->items)) {
                $this->selectedItem  = $direction === 'UP'
                    ? end($itemKeys)
                    : reset($itemKeys);
            }
        } while (!$this->canSelect());
    }

    /**
     * Move the selection in a given direction, left / right
     */
    protected function moveSelectionHorizontally(string $direction) : void
    {
        if (!$this->items[$this->selectedItem] instanceof SplitItem) {
            return;
        }

        /** @var SplitItem $item */
        $item = $this->items[$this->selectedItem];
        $itemKeys = array_keys($item->getItems());
        $selectedItemIndex = $item->getSelectedItemIndex();

        do {
            $direction === 'LEFT'
                ? $selectedItemIndex--
                : $selectedItemIndex++;

            if (!array_key_exists($selectedItemIndex, $item->getItems())) {
                $selectedItemIndex = $direction === 'LEFT'
                    ? end($itemKeys)
                    : reset($itemKeys);
            }
        } while (!$item->canSelectIndex($selectedItemIndex));

        $item->setSelectedItemIndex($selectedItemIndex);
    }

    /**
     * Can the currently selected item actually be selected?
     *
     * For example:
     *  selectable item -> yes
     *  static item -> no
     *  split item with only static items -> no
     *  split item with at least one selectable item -> yes
     *
     * @return bool
     */
    private function canSelect() : bool
    {
        return $this->items[$this->selectedItem]->canSelect();
    }

    /**
     * Retrieve the item the user actually selected
     *
     */
    public function getSelectedItem() : MenuItemInterface
    {
        if (null === $this->selectedItem) {
            throw new \RuntimeException('No selected item');
        }

        $item = $this->items[$this->selectedItem];
        return $item instanceof SplitItem
            ? $item->getSelectedItem()
            : $item;
    }

    /**
     * Execute the current item
     */
    protected function executeCurrentItem() : void
    {
        $item = $this->getSelectedItem();

        if ($item->canSelect()) {
            $callable = $item->getSelectAction();
            $callable($this);
        }
    }

    /**
     * If true we clear the whole terminal screen, useful
     * for example when reducing the width of the menu, to not
     * leave leftovers of the previous wider menu.
     *
     * Redraw the menu
     */
    public function redraw(bool $clear = false) : void
    {
        if ($clear) {
            $this->terminal->clear();
        }

        $this->assertOpen();
        $this->draw();
    }

    private function assertOpen() : void
    {
        if (!$this->isOpen()) {
            throw new MenuNotOpenException;
        }
    }

    /**
     * Draw the menu to STDOUT
     */
    protected function draw() : void
    {
        $frame = new Frame;

        $frame->newLine(2);

        if ($this->style->getBorderTopWidth() > 0) {
            $frame->addRows($this->style->getBorderTopRows());
        }

        if ($this->style->getPaddingTopBottom() > 0) {
            $frame->addRows($this->style->getPaddingTopBottomRows());
        }

        if ($this->title) {
            $frame->addRows($this->drawMenuItem(new StaticItem($this->title)));
            $frame->addRows($this->drawMenuItem(new LineBreakItem($this->style->getTitleSeparator())));
        }

        array_map(function ($item, $index) use ($frame) {
            $frame->addRows($this->drawMenuItem($item, $index === $this->selectedItem));
        }, $this->items, array_keys($this->items));


        if ($this->style->getPaddingTopBottom() > 0) {
            $frame->addRows($this->style->getPaddingTopBottomRows());
        }

        if ($this->style->getBorderBottomWidth() > 0) {
            $frame->addRows($this->style->getBorderBottomRows());
        }

        $frame->newLine(2);

        $this->terminal->moveCursorToTop();
        foreach ($frame->getRows() as $row) {
            if ($row == "\n") {
                $this->terminal->clearLine();
            }
            $this->terminal->write($row);
        }
        $this->terminal->clearDown();

        $this->currentFrame = $frame;
    }

    /**
     * Draw a menu item
     */
    protected function drawMenuItem(MenuItemInterface $item, bool $selected = false) : array
    {
        $rows = $item->getRows($this->style, $selected);

        if ($item instanceof SplitItem) {
            $selected = false;
        }

        $invertedColoursSetCode = $selected
            ? $this->style->getInvertedColoursSetCode()
            : '';
        $invertedColoursUnsetCode = $selected
            ? $this->style->getInvertedColoursUnsetCode()
            : '';

        if ($this->style->getBorderLeftWidth() || $this->style->getBorderRightWidth()) {
            $borderColour = $this->style->getBorderColourCode();
        } else {
            $borderColour = '';
        }

        return array_map(function ($row) use ($invertedColoursSetCode, $invertedColoursUnsetCode, $borderColour) {
            return sprintf(
                "%s%s%s%s%s%s%s%s%s%s%s%s\n",
                str_repeat(' ', $this->style->getMargin()),
                $borderColour,
                str_repeat(' ', $this->style->getBorderLeftWidth()),
                $this->style->getColoursSetCode(),
                $invertedColoursSetCode,
                str_repeat(' ', $this->style->getPaddingLeftRight()),
                $row,
                str_repeat(' ', $this->style->getRightHandPadding(mb_strlen(s::stripAnsiEscapeSequence($row)))),
                $invertedColoursUnsetCode,
                $borderColour,
                str_repeat(' ', $this->style->getBorderRightWidth()),
                $this->style->getColoursResetCode()
            );
        }, $rows);
    }

    /**
     * @throws InvalidTerminalException
     */
    public function open() : void
    {
        if ($this->isOpen()) {
            return;
        }

        if (count($this->items) === 0) {
            throw new \RuntimeException('Menu must have at least 1 item before it can be opened');
        }
        if($this->getRequiredTerminalWidth() !== null){
            if($this->getRequiredTerminalWidth() > $this->terminal->getWidth()){
                throw new \RuntimeException(sprintf("Terminal window must be %s columns wide, please increase the size of the window", $this->getRequiredTerminalWidth()));
            }
        }

        $this->configureTerminal();
        $this->open = true;
        $this->display();
    }

    /**
     * Close the menu
     *
     * @throws InvalidTerminalException
     */
    public function close() : void
    {
        $menu = $this;

        do {
            $menu->closeThis();
            $menu = $menu->getParent();
        } while (null !== $menu);

        $this->tearDownTerminal();
    }

    public function closeThis() : void
    {
        $this->terminal->clean();
        $this->terminal->moveCursorToTop();
        $this->open = false;
    }

    /**
     * @return MenuItemInterface[]
     */
    public function getItems() : array
    {
        return $this->items;
    }

    public function removeItem(MenuItemInterface $item) : void
    {
        $key = array_search($item, $this->items, true);

        if (false === $key) {
            throw new \InvalidArgumentException('Item does not exist in menu');
        }

        unset($this->items[$key]);
        $this->items = array_values($this->items);

        if ($this->selectedItem === $key) {
            $this->selectedItem = null;
            $this->selectFirstItem();
        }
    }

    public function getStyle() : MenuStyle
    {
        return $this->style;
    }

    public function setStyle(MenuStyle $style) : void
    {
        $this->style = $style;
    }

    public function getCurrentFrame() : Frame
    {
        return $this->currentFrame;
    }

    public function flash(string $text, MenuStyle $style = null) : Flash
    {
        $this->guardSingleLine($text);

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('yellow')
            ->setFg('red');

        return new Flash($this, $style, $this->terminal, $text);
    }

    public function confirm(string $text, MenuStyle $style = null) : Confirm
    {
        $this->guardSingleLine($text);

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('yellow')
            ->setFg('red');

        return new Confirm($this, $style, $this->terminal, $text);
    }
	     /**
      * @param string $text
      * @return YesNo
      */
     public function choice($text, MenuStyle $style = null)
     {
         if (strpos($text, "\n") !== false) {
             throw new \InvalidArgumentException;
         }

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('240')
            ->setFg('154');

         return new Choice($this, $style, $this->terminal, $text);
     }
    public function yesNoConfirm(string $question, \Closure $callback, MenuStyle $style = null) : void
    {
        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('240')
            ->setFg('154');

         $this->choice(sprintf($question),$style)
             ->setLeftButton('No', false)
             ->setRightButton('Yes', true)
             ->setOptionValue(false)
             ->display(function ($res) use ($callback) {
                 if($res){
                     $callback();
                 }
             });
    }




    public function askNumber(MenuStyle $style = null) : Number
    {
        $this->assertOpen();

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('yellow')
            ->setFg('red');

        return new Number(new InputIO($this, $this->terminal), $style);
    }

    public function askText(MenuStyle $style = null) : Text
    {
        $this->assertOpen();

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('yellow')
            ->setFg('red');

        return new Text(new InputIO($this, $this->terminal), $style);
    }

    public function askPassword(MenuStyle $style = null) : Password
    {
        $this->assertOpen();

        $style = $style ?? (new MenuStyle($this->terminal))
            ->setBg('yellow')
            ->setFg('red');

        return new Password(new InputIO($this, $this->terminal), $style);
    }

    private function guardSingleLine($text) : void
    {
        if (strpos($text, "\n") !== false) {
            throw new \InvalidArgumentException;
        }
    }
}
