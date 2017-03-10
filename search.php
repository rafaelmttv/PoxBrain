<?php 
require_once('./mysqlaccess.php');
require_once('references/Champion.php');
preinit($mysqli, true, true, true, true, true, true, true);
$userIsLoggedIn = $auth->isLoggedIn();
if ($userIsLoggedIn) {
    // user is signed in
    $userID = $auth->getUserId();
    //retrieve all champion records from the UserCollection
    $query = "SELECT * FROM UserCollection WHERE UserID = " . $userID;
    $userCollectionQuery = $mysqli->query($query);
    
    $userChamps = array();
    $userSpells = array();
    $userRelics = array();
    $userEquipment = array();
    while($row = $userCollectionQuery->fetch_assoc()) {
        if($row["Type"] == 1) {
            $userChamps[$row["RuneID"]] = array("RuneID" => $row["RuneID"], "Quantity" => $row["Quantity"], "Level" => $row["Level"]);
        } else if($row["Type"] == 2) {
            $userRelics[$row["RuneID"]] = array("RuneID" => $row["RuneID"], "Quantity" => $row["Quantity"], "Level" => $row["Level"]);
        } else if($row["Type"] == 3) {
            $userSpells[$row["RuneID"]] = array("RuneID" => $row["RuneID"], "Quantity" => $row["Quantity"], "Level" => $row["Level"]);
        } else if($row["Type"] == 4) {
            $userEquipment[$row["RuneID"]] = array("RuneID" => $row["RuneID"], "Quantity" => $row["Quantity"], "Level" => $row["Level"]);
        }
    }
}
else {
    // user is *not* signed in yet
}

function appendQuantity($array, $id) {
    global $userIsLoggedIn;
    if($userIsLoggedIn) {
        return " ● ".$userChamps[$row["ID"]]["Quantity"]." owned";
    } else {
        return "";
    }
}

$searchData = $_GET["search"];
$originalSearchTerm = $searchData;
$abilityNames = array();

//check string for ability matches and pre-remove
$success = preg_match_all('/(ability:"(.+?))"/', $searchData, $matches);
if($success >= 0 && count($matches[0]) > 0) {
    //there was a match
    //identify which abilities to search for

    $currentPos = 0;
    do {
        $abilityName = $matches[2][$currentPos];
        array_push($abilityNames, $abilityName);
        $searchData = str_replace($matches[0][$currentPos], "", $searchData);
        $currentPos = $currentPos + 1;
    } while ($currentPos < count($matches[0]));
}

$abilityINParams = " = 'FAKE_ABILITY_NAME'";
$extraS = "";
if(count($abilityNames) > 0) {
    $abilityINParams = " IN (".implode(',',array_fill(0,count($abilityNames),'?')).")";
    $extraS = implode('', array_fill(0, count($abilityNames)*4, 's'));
}
//begin building an array for call_user_func_array in params
$abilityParams = array_merge(array("ssssss".$extraS, $searchData, $searchData), $abilityNames, array($searchData), $abilityNames, array($searchData, $searchData), $abilityNames, array($searchData), $abilityNames);


//Find everywhere
//We'll start with champions
if (!($dbCheck = $mysqli->prepare("SELECT ID, Name, MATCH(Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1, MATCH(Name, Description, Rarity, Artist, RuneSet) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2 FROM Champions WHERE MATCH(Name, Description, Rarity, Artist, RuneSet) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY Score1*10 DESC, Score2 DESC"))) {
    echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; die();
}

if (!$dbCheck->bind_param("sss", $searchData, $searchData, $searchData)) {
    echo "Binding parameters failed: (" . $dbCheck->errno . ") " . $dbCheck->error; die();
}

if (!$dbCheck->execute()) {
    echo "Execute failed: (" . $dbCheck->errno . ") " . $dbCheck->error;  die();
}

$dbCheck->bind_result($champID, $name, $score1, $score2);
$allItems = [];
$champScores = array();

/* fetch values */
//Add relevancy
while ($dbCheck->fetch()) {
    $score = max($score1*10, $score2);
    $champScores[$champID] = array("ID"=> $champID, "Name"=>$name, "Type"=> 1, "Score" => $score);
}

$dbCheck->close();

//Abilities
if(!($dbCheck = $mysqli->prepare("SELECT ChampAbility.ChampID, Ability.Name, MATCH(Ability.Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1, " .
                        "MATCH(Ability.Name, Ability.Description) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2, " .
                        "CASE WHEN Ability.Name ".$abilityINParams." THEN 50 ELSE 0 END as AbilityScore, " .
                        "1 as BaseAbility " . 
                        "FROM Ability INNER JOIN ChampAbility ON Ability.ID = ChampAbility.AbilityID " .
                        "WHERE MATCH(Ability.Name, Ability.Description) AGAINST (? IN NATURAL LANGUAGE MODE) " .
                        "OR Ability.Name ".$abilityINParams." " .
                        "UNION ALL " .
                        "SELECT AbilitySet.ChampID, Ability.Name, MATCH(Ability.Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1,  " .
                        "MATCH(Ability.Name, Ability.Description) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2, " .
                        "CASE WHEN Ability.Name ".$abilityINParams." THEN 50 ELSE 0 END as AbilityScore, " .
                        "AbilitySet.DefaultAbility AS BaseAbility " .
                        "FROM Ability INNER JOIN AbilitySet ON Ability.ID = AbilitySet.AbilityID " .
                        "WHERE MATCH(Ability.Name, Ability.Description) AGAINST (? IN NATURAL LANGUAGE MODE) " .
                        "OR Ability.Name ".$abilityINParams." " .
                        "ORDER BY Score1*10 DESC, Score2 DESC"))) {
    echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; die();
}

$tmp = array();
foreach($abilityParams as $key => $value) $tmp[$key] = &$abilityParams[$key];

if (!call_user_func_array(array($dbCheck, "bind_param"), $tmp)) {
    echo "Binding parameters failed: (" . $dbCheck->errno . ") " . $dbCheck->error; die();
}

if (!$dbCheck->execute()) {
    echo "Execute failed: (" . $dbCheck->errno . ") " . $dbCheck->error;  die();
}

$dbCheck->bind_result($champID, $name, $score1, $score2, $abilityScore, $baseAbility);
$champAbilityScores = [];

while($dbCheck->fetch()) {
    $score = max($score1*10, $score2);
    $score = $score/10;//Abilities from champions shouldn't have such a high rating.
    if($baseAbility === 1) {
        $score = $score + $abilityScore;
    } else {
        $score = $score + $abilityScore/5;
    }
    array_push($champAbilityScores,  array("ID"=> $champID, "Name"=> $name, "Type"=> 1, "Score" => $score));
}

$dbCheck->close();

//Merge all champ notifications
foreach ($champAbilityScores as $anAbilityScore) {
    if(isset($champScores[$anAbilityScore["ID"]])) {
        $champScores[$anAbilityScore["ID"]]["Score"] = $champScores[$anAbilityScore["ID"]]["Score"] + $anAbilityScore["Score"];
    } else {
        $champScores[$anAbilityScore["ID"]] = $anAbilityScore;
    }
}
foreach($champScores as $aChamp) {
    array_push($allItems, $aChamp);
}

//Spells...
if (!($dbCheck = $mysqli->prepare("SELECT ID, Name, MATCH(Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1, MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2 FROM Spells WHERE MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY Score1*10 DESC, Score2 DESC"))) {
    echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; die();
}

if (!$dbCheck->bind_param("sss", $searchData, $searchData, $searchData)) {
    echo "Binding parameters failed: (" . $dbCheck->errno . ") " . $dbCheck->error; die();
}

if (!$dbCheck->execute()) {
    echo "Execute failed: (" . $dbCheck->errno . ") " . $dbCheck->error;  die();
}

$dbCheck->bind_result($spellID, $name, $score1, $score2);

while ($dbCheck->fetch()) {
    $score = max($score1*10, $score2);
    array_push($allItems,  array("ID"=> $spellID, "Name"=> $name, "Type"=> 3, "Score" => $score));
}

$dbCheck->close();

//Relics...
if (!($dbCheck = $mysqli->prepare("SELECT ID, Name, MATCH(Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1, MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2 FROM Relics WHERE MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY Score1*10 DESC, Score2 DESC"))) {
    echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; die();
}

if (!$dbCheck->bind_param("sss", $searchData, $searchData, $searchData)) {
    echo "Binding parameters failed: (" . $dbCheck->errno . ") " . $dbCheck->error; die();
}

if (!$dbCheck->execute()) {
    echo "Execute failed: (" . $dbCheck->errno . ") " . $dbCheck->error;  die();
}

$dbCheck->bind_result($relicID, $name, $score1, $score2);

while ($dbCheck->fetch()) {
    $score = max($score1*10, $score2);
    array_push($allItems,  array("ID"=> $relicID, "Name"=> $name, "Type"=> 2, "Score" => $score));
}

$dbCheck->close();

//Equipment...
if (!($dbCheck = $mysqli->prepare("SELECT ID, Name, MATCH(Name) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score1, MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) AS Score2 FROM Equipment WHERE MATCH(Name, Description, Rarity, Artist, RuneSet, FlavorText) AGAINST (? IN NATURAL LANGUAGE MODE) ORDER BY Score1*10 DESC, Score2 DESC"))) {
    echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error; die();
}

if (!$dbCheck->bind_param("sss", $searchData, $searchData, $searchData)) {
    echo "Binding parameters failed: (" . $dbCheck->errno . ") " . $dbCheck->error; die();
}

if (!$dbCheck->execute()) {
    echo "Execute failed: (" . $dbCheck->errno . ") " . $dbCheck->error;  die();
}

$dbCheck->bind_result($equipmentID, $name, $score1, $score2);

while ($dbCheck->fetch()) {
    $score = max($score1*10, $score2);
    array_push($allItems,  array("ID"=> $equipmentID, "Name"=> $name, "Type"=> 4, "Score" => $score));
}

$dbCheck->close();

function cmp($a, $b)
{
    return floor($b["Score"]*1000000 - $a["Score"]*1000000);
}
usort($allItems, "cmp");

$allItems = getData($allItems);

function convertFaction($str) {
    $str = str_replace("Forglar Swamp", "Forglar-Swamp", $str);
    $str = str_replace("Forsaken Wastes", "Forsaken-Wastes", $str);
    $str = str_replace("Ironfist Stronghold", "Ironfist-Stronghold", $str);
    $str = str_replace("K'thir Forest", "Kthir-Forest", $str);
    $str = str_replace("Savage Tundra", "Savage-Tundra", $str);
    $str = str_replace("Shattered Peaks", "Shattered-Peaks", $str);
    $str = str_replace("Sundered Lands", "Sundered-Lands", $str);
    $str = str_replace("Underdepths", "Underdepths", $str);
    return $str;
}

?>
<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- The above 3 meta tags *must* come first in the head; any other head content must come *after* these tags -->

    <title>Search - <?php echo $_GET["search"]; ?></title>

    <!-- Bootstrap core CSS -->
    <link href="css/bootstrap.min.css" rel="stylesheet">
    <link href="css/search.css" rel="stylesheet">

    <!-- Custom styles for this template -->
    <link href="css/jumbotron.css" rel="stylesheet">
    <link href="css/main.css" rel="stylesheet">
  </head>

  <body>
    
    <?php require_once('./header.php'); ?>
    <!--<div class="factions">
      <div class="container">
        <div class="col-md-6">
            <div id="f-s" class="col-md-3">
                Forglar Swamp
            </div>
            <div id="f-w" class="col-md-3">
                Forsaken Wastes
            </div>
            <div id="i-s" class="col-md-3">
                Ironfist Stronghold
            </div>
            <div id="k-f" class="col-md-3">
                K'thir Forest
            </div>
        </div>
        <div class="col-md-6">
            <div id="s-t" class="col-md-3">
                Savage Tundra
            </div>
            <div id="s-p" class="col-md-3">
                Shattered Peaks
            </div>
            <div id="s-l" class="col-md-3">
                Sundered Lands
            </div>
            <div id="u-d" class="col-md-3">
                Underdepths
            </div>
        </div>
      </div>
    </div>-->
    
    <div class="container" style="padding-top: 20px;">
        <div id="search-field">
            <form method="get" action="search.php">
                <input id="page-search" class="form-control" type="text" name="search" value="<?php echo htmlspecialchars($originalSearchTerm); ?>"/>
                <input class="btn btn-success" type="submit" value="Search again"/>
            </form>
        </div>
      <div id="filters">
          <p>Filters:</p>
          <div class="filter-section">
              Types: <input class="global" type="checkbox" checked name="type" />
              <label><input class="filter-box type" type="checkbox" checked name="champion"/>Champions</label>
              <label><input class="filter-box type" type="checkbox" checked name="relic"/>Relics</label>
              <label><input class="filter-box type" type="checkbox" checked name="spell"/>Spells</label>
              <label><input class="filter-box type" type="checkbox" checked name="equipment"/>Equipment</label>
          </div>
          <div class="filter-section">
              Factions: <input class="global" type="checkbox" checked name="faction" />
              <label><input class="filter-box faction" type="checkbox" checked name="Forglar-Swamp"/>Forglar swamp</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Forsaken-Wastes"/>Forsaken Wastes</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Ironfist-Stronghold"/>Ironfist Stronghold</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Kthir-Forest"/>K'thir Forest</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Savage-Tundra"/>Savage Tundra</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Shattered-Peaks"/>Shattered Peaks</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Sundered-Lands"/>Sundered Lands</label>
              <label><input class="filter-box faction" type="checkbox" checked name="Underdepths"/>Underdepths</label>
          </div>
          <div class="filter-section">
              Rarity: <input class="global" type="checkbox" checked name="rarity" />
              <label><input class="filter-box rarity" type="checkbox" checked name="COMMON"/>Common</label>
              <label><input class="filter-box rarity" type="checkbox" checked name="UNCOMMON"/>Uncommon</label>
              <label><input class="filter-box rarity" type="checkbox" checked name="RARE"/>Rare</label>
              <label><input class="filter-box rarity" type="checkbox" checked name="EXOTIC"/>Exotic</label>
              <label><input class="filter-box rarity" type="checkbox" checked name="LEGENDARY"/>Legendary</label>
              <label><input class="filter-box rarity" type="checkbox" checked name="LIMITED"/>Limited</label>
          </div>
      </div>
      <div id="search-results">
          <?php 
            foreach ($allItems as $anItem) {
                if($anItem["Type"] == 1) { ?>
                    <div class="search-item champion <?php echo convertFaction($anItem["Faction"])." ".$anItem["Rarity"]." ".$anItem["Race"]." ".$anItem["Class"]; ?>" >
                        <a href="/rune.php?id=<?php echo $anItem["ID"]; ?>&type=1">
                            <h3 class="item-title">
                                <img class="img-small" src="https://d2aao99y1mip6n.cloudfront.net/images/runes/sm/<?php echo $anItem['Hash']; ?>.png" />
                                <?php echo $anItem["Name"] ?>
                            </h3>
                        </a>
                        <div class="score"><?php echo $anItem["Score"]; ?></div>
                        <div class="stats"><?php echo $anItem["Damage"]."DMG, ".$anItem["Speed"]."SPD, ".$anItem["MinRng"]."-".$anItem["MaxRng"]."RNG, ".$anItem["Defense"]."DEF, ".$anItem["HitPoints"]."HP "; ?></div>
                        <div class="flavor"><?php echo $anItem["Description"]; ?></div>
                        <div class="nora"><?php echo $anItem["NoraCost"] ?></div>
                        <div class="owned"><?php if($userIsLoggedIn) {echo $userChamps[$anItem["ID"]]["Quantity"]." owned"; } ?></div>
                        <div class="rarity"><?php echo $anItem["Rarity"]; ?></div>
                        <div class="rune-set"><?php echo $anItem["RuneSet"]; ?></div>
                        <div class="artist"><?php echo $anItem["Artist"]; ?></div>
                        <div class="ability-set row">
                            <div class="base col-sm-4">
                                <span>Base</span>
                                <ul>
                                <?php foreach($anItem["StartingAbilities"] as $baseAbility) { ?>
                                    <a href="/rune.php?id=<?php echo $baseAbility["ID"]; ?>&type=7"><li class="ability"><?php echo $baseAbility["Name"] ?></li></a>
                                <?php } ?>
                                </ul>
                            </div>
                            <div class="upgrade-1 col-sm-4">
                                <span>Upgrade line 1</span>
                                <ul>
                                <?php foreach($anItem["AbilitySet"][1] as $anUpgrade) { ?>
                                    <a href="/rune.php?id=<?php echo $anUpgrade["ID"]; ?>&type=7"><li class="ability"><?php echo $anUpgrade["Name"]; if($anUpgrade["Default"]) { echo " (Default)"; } ?></li></a>
                                <?php } ?>
                                </ul>
                            </div>
                            <div class="upgrade-2 col-sm-4">
                                <span>Upgrade line 2</span>
                                <ul>
                                <?php foreach($anItem["AbilitySet"][2] as $anUpgrade) { ?>
                                   <a href="/rune.php?id=<?php echo $anUpgrade["ID"]; ?>&type=7"> <li class="ability"><?php echo $anUpgrade["Name"]; if($anUpgrade["Default"]) { echo " (Default)"; } ?></li></a>
                                <?php } ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                <?php } else if($anItem["Type"] == 2) { ?>
                    <div class="search-item relic <?php echo convertFaction($anItem["Faction"])." ".$anItem["Rarity"]; ?>">
                        <a href="/rune.php?id=<?php echo $anItem["ID"]; ?>&type=2">
                            <h3 class="item-title">
                                <img class="img-small" src="https://d2aao99y1mip6n.cloudfront.net/images/runes/sm/<?php echo $anItem['Hash']; ?>.png" />
                                <?php echo $anItem["Name"] ?>
                            </h3>
                        </a>
                        <div class="score"><?php echo $anItem["Score"]; ?></div>
                        <div><?php echo $anItem["Description"]; ?></div>
                        <div class="nora"><?php echo $anItem["NoraCost"] ?></div>
                        <div class="owned"><?php if($userIsLoggedIn) {echo $userRelics[$anItem["ID"]]["Quantity"]." owned"; } ?></div>
                        <div class="flavor"><?php echo $anItem["FlavorText"]; ?></div>
                        <div class="rarity"><?php echo $anItem["Rarity"]; ?></div>
                        <div class="rune-set"><?php echo $anItem["RuneSet"]; ?></div>
                        <div class="artist"><?php echo $anItem["Artist"]; ?></div>
                    </div>
                <?php } else if($anItem["Type"] == 3) { ?>
                    <div class="search-item spell <?php echo convertFaction($anItem["Faction"])." ".$anItem["Rarity"]; ?>">
                        <a href="/rune.php?id=<?php echo $anItem["ID"]; ?>&type=3">
                            <h3 class="item-title">
                                <img class="img-small" src="https://d2aao99y1mip6n.cloudfront.net/images/runes/sm/<?php echo $anItem['Hash']; ?>.png" />
                                <?php echo $anItem["Name"] ?>
                            </h3>
                        </a>
                        <div class="score"><?php echo $anItem["Score"]; ?></div>
                        <div><?php echo $anItem["Description"]; ?></div>
                        <div class="nora"><?php echo $anItem["NoraCost"] ?></div>
                        <div class="owned"><?php if($userIsLoggedIn) {echo $userSpells[$anItem["ID"]]["Quantity"]." owned"; } ?></div>
                        <div class="flavor"><?php echo $anItem["FlavorText"]; ?></div>
                        <div class="rarity"><?php echo $anItem["Rarity"]; ?></div>
                        <div class="rune-set"><?php echo $anItem["RuneSet"]; ?></div>
                        <div class="artist"><?php echo $anItem["Artist"]; ?></div>
                    </div>
                <?php } else if($anItem["Type"] == 4) { ?>
                    <div class="search-item equipment <?php echo convertFaction($anItem["Faction"])." ".$anItem["Rarity"]; ?>">
                        <a href="/rune.php?id=<?php echo $anItem["ID"]; ?>&type=4">
                            <h3 class="item-title">
                                <img class="img-small" src="https://d2aao99y1mip6n.cloudfront.net/images/runes/sm/<?php echo $anItem['Hash']; ?>.png" />
                                <?php echo $anItem["Name"] ?>
                            </h3>
                        </a>
                        <div class="score"><?php echo $anItem["Score"]; ?></div>
                        <div><?php echo $anItem["Description"]; ?></div>
                        <div class="nora"><?php echo $anItem["NoraCost"] ?></div>
                        <div class="owned"><?php if($userIsLoggedIn) {echo $userEquipment[$anItem["ID"]]["Quantity"]." owned"; } ?></div>
                        <div class="flavor"><?php echo $anItem["FlavorText"]; ?></div>
                        <div class="rarity"><?php echo $anItem["Rarity"]; ?></div>
                        <div class="rune-set"><?php echo $anItem["RuneSet"]; ?></div>
                        <div class="artist"><?php echo $anItem["Artist"]; ?></div>
                    </div>
                <?php }
            }
            if(count($allItems) == 0) { ?>
                <div class="no-results">
                    No results. <br />
                    Try some of the following tips to refine your search and get some results: <br />
                    Any 3-letter word or less is ignored <br />
                    Common words are ignored, like "the" or "some" <br />
                </div>
            <?php }
          ?>
      </div>
    </div>
    
    <?php require_once('./js/corejs.php'); ?>
    <script>
        function onFilter(e) {
            $(".search-item").removeClass("hide");
            var filterList = $(".filter-box");
            for(var i = 0; i < filterList.length; i++) {
                if(!filterList[i].checked) {
                    $("." + filterList[i].name).addClass("hide");
                }
            }
            
            if(e != undefined) {
                var classes = $(e.currentTarget).attr('class');
                classes = classes.replace('filter-box', '').trim();
                console.log(classes);
                
                var lastKnownCheck = $("." + classes)[0].checked;
                var indeterminate  = false;
                for(var i = 1; i < $("." + classes).length; i++) {
                    if(lastKnownCheck == $("." + classes)[i].checked) {
                        //All ok
                    } else {
                        indeterminate  = true;
                    }
                }
                
                var allGlobals = $(".global");
                for(var i = 0; i < allGlobals.length; i++) {
                    if(allGlobals[i].name == classes) {
                        allGlobals[i].indeterminate = indeterminate ;
                        if(!indeterminate) {
                            allGlobals[i].checked = lastKnownCheck;
                        }
                    }
                }
            }
        }
        function globalFilter(e) {
            console.log(e.currentTarget.name);
            var subs = $("." + e.currentTarget.name);
            for(var i = 0; i < subs.length; i++) {
                subs[i].checked = e.currentTarget.checked;
            }
            onFilter();
        }
        $(document).ready(function() {
            console.log("document ready");
           <?php 
                if(!isset($_GET["score"]) || $_GET["score"] != "1") { ?>
                $(".score").addClass("hide");
                <?php }
            ?>
            $(".filter-box").on("change", onFilter);
            $(".global").on("change", globalFilter);
            onFilter();
        });
    </script>
    </body>
</html>