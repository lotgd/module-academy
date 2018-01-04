<?php
declare(strict_types=1);

namespace LotGD\Module\Training\Tests;

use Doctrine\Common\Util\Debug;
use LotGD\Core\Events\EventContext;
use LotGD\Core\Events\EventContextData;
use LotGD\Core\Game;
use LotGD\Core\Models\Character;
use LotGD\Core\Models\Viewpoint;
use LotGD\Module\Res\Fight\Fight;
use LotGD\Module\Res\Fight\Tests\helpers\EventRegistry;
use LotGD\Module\Res\Fight\Module as ResFightModule;

use LotGD\Module\Training\Module;

class ModuleTest extends ModuleTestCase
{
    const Library = 'lotgd/module-project';

    protected function getDataSet(): \PHPUnit_Extensions_Database_DataSet_YamlDataSet
    {
        return new \PHPUnit_Extensions_Database_DataSet_YamlDataSet(implode(DIRECTORY_SEPARATOR, [__DIR__, 'datasets', 'module.yml']));
    }

    public function testHandleUnknownEvent()
    {
        // Always good to test a non-existing event just to make sure nothing happens :).
        $context = new EventContext(
            "e/lotgd/tests/unknown-event",
            "none",
            EventContextData::create([])
        );

        Module::handleEvent($this->g, $context);
    }

    public function testTrainingAreaIsPresent()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find(1);
        $game->setCharacter($character);

        // New day
        $v = $game->getViewpoint();
        $this->assertSame("It is a new day!", $v->getTitle());
        // Village
        $action = $v->getActionGroups()[0]->getActions()[0];
        $game->takeAction($action->getId());
        $this->assertSame("Village", $v->getTitle());

        // Assert action to training yard
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 5], "Outside");

        // Assert yard exists
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $this->assertHasAction($v, ["getDestinationSceneId", 1], "Back");
    }

    protected function goToYard(int $characterId, callable $executeBeforeTakingActionToYard = null): array
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find($characterId);
        $game->setCharacter($character);

        // New day
        $v = $game->getViewpoint();
        $this->assertSame("It is a new day!", $v->getTitle());
        // Village
        $action = $v->getActionGroups()[0]->getActions()[0];
        $game->takeAction($action->getId());
        $this->assertSame("Village", $v->getTitle());
        // Training Yard
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 5], "Outside");

        if ($executeBeforeTakingActionToYard !== NULL) {
            $executeBeforeTakingActionToYard($game, $v, $character);
        }

        $game->takeAction($action->getId());

        return [$game, $v, $character];
    }

    public function testIfMasterTellsInexperiencedCharacterToComeBackLater()
    {
        [$game, $v, $character] = $this->goToYard(2);
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");

        // Set experience to 0 and ask the master.
        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 0);
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertHasAction($v, ["getDestinationSceneId", 1], "Back");
        $description = explode("\n\n", $v->getDescription());
        $this->assertContains("You approach Mieraband timidly and inquire as to your standing in the class.", $description);
        $this->assertContains("Mieraband states that you will need 100 more experience before you are ready to challenge him in battle.", $description);
    }

    public function testIfMasterTellsExperiencedCharacterThatHeIsReady()
    {
        [$game, $v, $character] = $this->goToYard(3);
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");

        // Set experience to 100 and ask the master.
        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100);
        $game->takeAction($action->getId());
        $this->assertSame("Bluspring's Warrior Training", $v->getTitle());
        $action = $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertHasAction($v, ["getDestinationSceneId", 1], "Back");
        $description = explode("\n\n", $v->getDescription());
        $this->assertContains("You approach Mieraband timidly and inquire as to your standing in the class.", $description);
        $this->assertContains("Mieraband says, \"Gee, your muscles are getting bigger than mine...\"", $description);
    }

    public function testIfDeadCharacterCannotChallengeOrQuestionTheMaster()
    {
        [$game, $v, $character] = $this->goToYard(
            4,
            function(Game $g, Viewpoint $v, Character $character) {
                $character->setHealth(0);
            }
        );

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
        $this->assertSame("You are dead. How are you going to challenge your master if you cannot even survive killing enemies? Come back tomorrow.", $v->getDescription());
    }

    public function testIfCharacterAbove14CannotChallengeOrQuestionTheMaster()
    {
        [$game, $v, $character] = $this->goToYard(5);

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfCharacterCannotChallengeOrQuestionMasterIfHeHasAlreadySeenHimToday()
    {
        [$game, $v, $character] = $this->goToYard(
            6,
            function(Game $g, Viewpoint $v, Character $c) {
                $c->setProperty(Module::CharacterPropertySeenMaster, true);
            }
        );

        $this->assertNotHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfMasterInstaDefeatsCharacterIfHeHasNotEnoughExperience()
    {
        [$game, $v, $character] = $this->goToYard(7);

        $this->assertHasAction($v, ["getTitle", "Question Master"], "The Yard");
        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $game->takeAction($action->getId());

        $this->assertTrue($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertHasAction($v, ["getDestinationSceneId", 1], "Back");
    }

    public function testIfCharacterCannotRechallengeMasterIfHeLooses()
    {
        [$game, $v, $character] = $this->goToYard(8);

        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100000);

        $game->takeAction($action->getId());
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
        $character->setHealth(0);

        // Attack until someone dies.
        do {
            $game->takeAction($action->getId());

            if ($character->getProperty(ResFightModule::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $descs = explode("\n\n", $v->getDescription());
        $descs = array_map("trim", $descs);
        $this->assertContains("You have been defeated by Mieraband. They stand over your dead body, laughting..", $descs);
        $this->assertTrue($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertNotHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }

    public function testIfCharacterCanRechallengeMasterIfHeWinsAndIfHeReallyIncreasesHisLevel()
    {
        [$game, $v, $character] = $this->goToYard(9);

        $action = $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");

        $character->setProperty(ResFightModule::CharacterPropertyCurrentExperience, 100000);

        $game->takeAction($action->getId());
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");

        // Attack until someone dies.
        do {
            $character->setHealth(10); // constantly heal.
            $game->takeAction($action->getId());

            if ($character->getProperty(ResFightModule::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $descs = explode("\n\n", $v->getDescription());
        $descs = array_map("trim", $descs);
        $this->assertContains("You defeated Mieraband. You gain a level!", $descs);

        $this->assertSame(2, $character->getLevel());
        $this->assertFalse($character->getProperty(Module::CharacterPropertySeenMaster));
        $this->assertHasAction($v, ["getTitle", "Challenge Master"], "The Yard");
    }


    public function _testModuleFlowWhileCharacterStaysAlive()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->findById(1)[0];
        $game->setCharacter($character);
        $v = $game->getViewpoint();

        // Assert new day happened
        $this->assertSame("It is a new day!", $v->getTitle());

        // Assert that our new day inserts work
        $descriptions = explode("\n\n", $v->getDescription());
        $this->assertContains("You feel energized! Today, you can fight for 20 rounds.", $descriptions);
        $this->assertSame($character->getMaxHealth(), $character->getHealth());
        $character->setHealth(90);

        // Should be in the village
        $action = $v->getActionGroups()[0]->getActions()[0];
        $game->takeAction($action->getId());
        $this->assertSame("Village", $v->getTitle());
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 5], "Outside");

        // Go to the forest
        $game->takeAction($action->getId());
        $this->assertSame("The Academy", $v->getTitle());
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 6], "Healing");
        $this->assertHasAction($v, ["getTitle", "Search for a fight"], "Fight");
        $this->assertHasAction($v, ["getTitle", "Go Thrillseeking"], "Fight");
        $this->assertHasAction($v, ["getTitle", "Go Slumming"], "Fight");

        // Go to the healer.
        $game->takeAction($action->getId());
        $this->assertSame("Healer's Hut", $v->getTitle());
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 5], "Back");

        // Back to the forest
        $game->takeAction($action->getId());
        $this->assertSame("The Academy", $v->getTitle());
        $action = $this->assertHasAction($v, ["getTitle", "Search for a fight"], "Fight");

        // Start a fight.
        $game->takeAction($action->getId());
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");

        // Attack until someone dies.
        do {
            $game->takeAction($action->getId());

            if ($character->getProperty(ResFightModule::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $this->assertSame("You won!", $v->getTitle());

        // Now go to healing.
        $action = $this->assertHasAction($v, ["getDestinationSceneId", 6], "Healing");
        $game->takeAction($action->getId());
        $this->assertSame("Healer's Hut", $v->getTitle());

        // Assert that we are not completely healed.
        $this->assertLessThan($character->getMaxHealth(), $character->getHealth());
        $action = $this->assertHasAction($v, ["getTitle", "Complete Healing"], "Potions");
        $game->takeAction($action->getId());
        // Assert we are.
        $this->assertEquals($character->getMaxHealth(), $character->getHealth());
    }

    public function _testIfHealingOptionsAreOnlyVisibleToDamagedCharacters()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find(2);
        $character->setProperty(\LotGD\Module\NewDay\Module::CharacterPropertyLastNewDay, new \DateTime());
        $game->setCharacter($character);
        $v = $game->getViewpoint();

        // Take actions
        $this->takeActions($game, $v, [5, 6]);
        $this->assertHasAction($v, ["getTitle", "Complete Healing"], "Potions");

        // Heal, go back and return
        $character->setHealth($character->getMaxHealth());
        $this->takeActions($game, $v, [5, 6]);
        $this->assertNotHasAction($v, ["getTitle", "Complete Healing"], "Potions");
    }

    public function _testIfHealerSuccessfullyRemovesHealthAboveMaximum()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find(3);
        $character->setProperty(\LotGD\Module\NewDay\Module::CharacterPropertyLastNewDay, new \DateTime());
        $game->setCharacter($character);
        $v = $game->getViewpoint();

        // Take actions
        $this->assertGreaterThan($character->getMaxHealth(), $character->getHealth());
        $this->takeActions($game, $v, [5, 6]);
        $this->assertNotHasAction($v, ["getTitle", "Complete Healing"], "Potions");
        $this->assertSame($character->getMaxHealth(), $character->getHealth());
    }

    public function _testIfDeadPeopleCannotFightOrHeal()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find(4);
        $character->setProperty(\LotGD\Module\NewDay\Module::CharacterPropertyLastNewDay, new \DateTime());
        $game->setCharacter($character);
        $v = $game->getViewpoint();

        // Take actions
        $this->assertSame(0, $character->getHealth());
        $this->takeActions($game, $v, [5]);
        $this->assertNotHasAction($v, ["getTitle", "Search for a fight"], "Fight");
        $this->takeActions($game, $v, [6]);
        $this->assertNotHasAction($v, ["getTitle", "Complete Healing"], "Potions");
    }

    public function _testIfAForestFightEndsProperlyIfTheCharacterDied()
    {
        /** @var Game $game */
        $game = $this->g;
        /** @var Character $character */
        $character = $this->getEntityManager()->getRepository(Character::class)->find(5);
        $character->setProperty(\LotGD\Module\NewDay\Module::CharacterPropertyLastNewDay, new \DateTime());
        $game->setCharacter($character);
        $v = $game->getViewpoint();

        // Take actions
        $this->assertSame(1, $character->getHealth());
        $this->takeActions($game, $v, [5, "Go Thrillseeking"]);
        $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");

        // Attack until someone dies.
        // Make sure we die.
        $character->setLevel(1);
        do {
            $game->takeAction($action->getId());

            if ($character->getProperty(Module::CharacterPropertyBattleState) !== null){
                $action = $this->assertHasAction($v, ["getTitle", "Attack"], "Fight");
            } else {
                break;
            }
        } while (true);

        $this->assertSame("You died!", $v->getTitle());
        $this->assertNotHasAction($v, ["getTitle", "Search for a fight"], "Fight");
        $this->assertNotHasAction($v, ["getTitle", "Go Thrillseeking"], "Fight");
        $this->assertNotHasAction($v, ["getTitle", "Go Slumming"], "Fight");
        $this->assertHasAction($v, ["getDestinationSceneId", 1], "Back");
    }
}
