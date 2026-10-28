<?php
declare(strict_types=1);
/** Only the three empty tables introduced by the withdrawn student feature. */
function community_retired_workspace_tables(PDO $db):array {
 $tables=['community_workspace_history','community_workspace_drafts','community_workspace_access'];$present=[];
 foreach($tables as $table){
  if($db->getAttribute(PDO::ATTR_DRIVER_NAME)==='sqlite'){$q=$db->prepare("SELECT 1 FROM sqlite_master WHERE type='table' AND name=?");$q->execute([$table]);}
  else{$q=$db->prepare('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');$q->execute([$table]);}
  if($q->fetchColumn())$present[]=$table;
 }return $present;
}
function community_retire_student_workspace(PDO $db):void {
 neptune_migration_apply($db,'community.student.workspace.removed.v1','Neptune_Tag_Scouting_Simple_v1.zip',function()use($db):void{
  $tables=community_retired_workspace_tables($db);
  // Refuse to erase any saved record; check every table before changing any schema.
  foreach($tables as $table)if((int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn()>0)throw new RuntimeException('Student workspace removal found saved records. Archive those records before retrying.');
  foreach($tables as $table)$db->exec('DROP TABLE IF EXISTS '.$table);
 });
}
