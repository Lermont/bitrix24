<?php
use Bitrix\Main\Localization\Loc;

Loc::loadMessages(__FILE__);

if(!check_bitrix_sessid()) return;

if($errorException = $APPLICATION->GetException()){
    echo CAdminMessage::ShowMessage($errorException->GetString());
} else {
    echo CAdminMessage::ShowNote(Loc::getMessage("DRAFT_APP_STEP_SUBMIT_BACK"));
}
?>
<form action="<?echo $APPLICATION->GetCurPage()?>">
    <input type="hidden" name="lang" value="<?echo LANG?>">
    <input type="submit" name="" value="<?echo Loc::getMessage("MOD_BACK")?>">
<form> 