/* PPB U2 | adapted directly from CSDA Public/sidebar.tpl; PPB storage keys. */

(function(){
    'use strict';

    var sidebar=document.getElementById('adminSidebar');
    var overlay=document.getElementById('adminSidebarOverlay');
    var collapseButton=document.getElementById('adminSidebarCollapseButton');
    var reopenButton=document.getElementById('adminSidebarReopenButton');
    var menuLinks=document.querySelectorAll('[data-menu-path]');
    var menuGroupToggles=document.querySelectorAll('[data-admin-menu-group-toggle="1"]');
    var collapsedKey='ppb_admin_sidebar_collapsed';
    var groupsKey='ppb_admin_menu_groups';

    function isDesktop(){return window.innerWidth>840}
    function normalizePath(path){
        if(!path){return ''}
        return path.replace(/\/+$/,'').toLowerCase();
    }
    function setCollapsed(collapsed,save){
        if(!isDesktop()){
            document.body.classList.remove('admin-sidebar-collapsed');
            return;
        }
        document.body.classList.toggle('admin-sidebar-collapsed',collapsed);
        if(save!==false){
            localStorage.setItem(collapsedKey,collapsed?'1':'0');
        }
    }
    function openMobile(){
        if(!sidebar){return}
        sidebar.classList.add('open');
        if(overlay){overlay.classList.add('show')}
    }
    function closeMobile(){
        if(!sidebar){return}
        sidebar.classList.remove('open');
        if(overlay){overlay.classList.remove('show')}
    }
    window.openAdminSidebar=openMobile;
    window.closeAdminSidebar=closeMobile;

    if(collapseButton){
        collapseButton.addEventListener('click',function(){setCollapsed(true,true)});
    }
    if(reopenButton){
        reopenButton.addEventListener('click',function(){setCollapsed(false,true)});
    }
    if(overlay){
        overlay.addEventListener('click',closeMobile);
    }

    var savedGroups={};
    try{savedGroups=JSON.parse(localStorage.getItem(groupsKey)||'{}')||{}}catch(e){savedGroups={}}
    for(var j=0;j<menuGroupToggles.length;j++){
        var toggle=menuGroupToggles[j];
        var group=toggle.closest('.admin-menu-group');
        if(!group){continue}
        var name=group.getAttribute('data-admin-menu-group');
        if(savedGroups[name]===1 && !group.querySelector('.admin-submenu-item.active')){
            group.classList.add('collapsed');
            toggle.setAttribute('aria-expanded','false');
        }
        toggle.addEventListener('click',function(){
            var targetGroup=this.closest('.admin-menu-group');
            if(!targetGroup){return}
            targetGroup.classList.toggle('collapsed');
            var collapsed=targetGroup.classList.contains('collapsed');
            this.setAttribute('aria-expanded',collapsed?'false':'true');
            var key=targetGroup.getAttribute('data-admin-menu-group');
            var states={};
            try{states=JSON.parse(localStorage.getItem(groupsKey)||'{}')||{}}catch(e){states={}}
            states[key]=collapsed?1:0;
            localStorage.setItem(groupsKey,JSON.stringify(states));
        });
    }

    if(isDesktop()){
        setCollapsed(localStorage.getItem(collapsedKey)==='1',false);
    }

    window.addEventListener('resize',function(){
        if(isDesktop()){closeMobile()}
        else{document.body.classList.remove('admin-sidebar-collapsed')}
    });
})();

(function(){
 const wrapper=document.getElementById('adminProfileWrapper');
 const trigger=document.getElementById('adminProfileButton');
 const menu=document.getElementById('adminProfileDropdown');
 if(!trigger||!menu)return;
 function close(){menu.classList.remove('show');trigger.classList.remove('active');trigger.setAttribute('aria-expanded','false')}
 trigger.addEventListener('click',function(e){e.stopPropagation();let next=!menu.classList.contains('show');close();if(next){menu.classList.add('show');trigger.classList.add('active');trigger.setAttribute('aria-expanded','true')}});
 document.addEventListener('click',function(e){if(!wrapper?.contains(e.target))close()});
 document.addEventListener('keydown',function(e){if(e.key==='Escape')close()});
 const mobile=document.getElementById('adminMobileMenuButton');
 if(mobile){mobile.addEventListener('click',function(){window.openAdminSidebar?.()})}
})();
