<?php
namespace App\Services;
use App\Models\Permission;
use App\Models\RolePermission;
use App\Models\User;
class AuthorizationService {
 public const PERMISSIONS=['dashboard.view','users.view','users.create','users.edit','users.delete','users.suspend','documents.view','documents.create','documents.edit','documents.delete','documents.export','ai.use','ai.manage','ai.view','ocr.use','voice.use','voice.manage','billing.view','billing.manage','finance.view','finance.manage','support.manage','settings.manage','integrations.manage','audit.view','access.manage','organization.manage','team.view','team.manage','workflows.view','workflows.manage','reports.view','reports.users','reports.team','reports.documents','reports.workflows','reports.ai','reports.resources','system.audit','documents.share'];
 public function allows(?User $user,string $permission):bool {
  if(!$user||$user->is_blocked)return false;
  if($user->isAdmin())return true;
  $id=Permission::where('key',$permission)->value('id');
  return $id ? RolePermission::where('role',$user->role)->where('permission_id',$id)->exists() : false;
 }
 public function syncDefaults():void {
  $labels=['dashboard.view'=>'داشبورد','users.view'=>'مشاهده کاربران','users.create'=>'ایجاد کاربر','users.edit'=>'ویرایش کاربر','users.delete'=>'حذف کاربر','users.suspend'=>'مسدودسازی','documents.view'=>'مشاهده اسناد','documents.create'=>'ایجاد سند','documents.edit'=>'ویرایش سند','documents.delete'=>'حذف سند','documents.export'=>'خروجی اسناد','ai.use'=>'استفاده از AI','ai.manage'=>'مدیریت AI','ai.view'=>'گزارش AI','ocr.use'=>'OCR','voice.use'=>'تایپ صوتی','voice.manage'=>'مدیریت صوت','billing.view'=>'مشاهده صورتحساب','billing.manage'=>'مدیریت صورتحساب','finance.view'=>'گزارش مالی','finance.manage'=>'مدیریت مالی','support.manage'=>'مدیریت پشتیبانی','settings.manage'=>'مدیریت تنظیمات','integrations.manage'=>'مدیریت یکپارچه‌سازی','audit.view'=>'مشاهده ممیزی','access.manage'=>'مدیریت دسترسی','organization.manage'=>'مدیریت سازمان','team.view'=>'مشاهده تیم','team.manage'=>'مدیریت تیم','workflows.view'=>'مشاهده گردش‌کار','workflows.manage'=>'مدیریت گردش‌کار','reports.view'=>'گزارش‌ها','reports.users'=>'گزارش کاربران','reports.team'=>'گزارش تیم','reports.documents'=>'گزارش اسناد','reports.workflows'=>'گزارش گردش‌کار','reports.ai'=>'گزارش AI','reports.resources'=>'گزارش منابع','system.audit'=>'نظارت سیستم','documents.share'=>'اشتراک‌گذاری'];
  foreach(self::PERMISSIONS as $key){Permission::firstOrCreate(['key'=>$key],['label'=>$labels[$key]??$key,'group'=>str($key)->before('.')->toString()]);}
  $all=Permission::pluck('id','key');
  $roles=['organization_manager'=>['dashboard.view','users.view','users.edit','documents.view','documents.create','documents.edit','documents.export','ai.use','ai.view','ocr.use','voice.use','billing.view','support.manage','organization.manage','team.view','team.manage','reports.view','reports.users','reports.team','reports.documents','reports.ai','reports.resources','documents.share'],
          'team_supervisor'=>['dashboard.view','users.view','documents.view','documents.create','documents.edit','documents.export','ai.use','ai.view','ocr.use','voice.use','team.view','reports.team','reports.documents','documents.share'],
          'employee'=>['dashboard.view','documents.view','documents.create','documents.edit','documents.export','ai.use','ocr.use','voice.use','team.view'],
          'observer'=>['dashboard.view','documents.view','ai.use','reports.view'],
          'guest'=>['dashboard.view','documents.view']];
  foreach($roles as $role=>$perms)foreach($perms as $key)if(isset($all[$key]))RolePermission::firstOrCreate(['role'=>$role,'permission_id'=>$all[$key]]);
 }
}
