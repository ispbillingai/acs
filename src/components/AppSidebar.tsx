
import React from "react";
import { Link, useLocation } from "react-router-dom";
import {
  Sidebar,
  SidebarContent,
  SidebarGroup,
  SidebarGroupContent,
  SidebarGroupLabel,
  SidebarMenu,
  SidebarMenuButton,
  SidebarMenuItem,
  SidebarHeader,
  SidebarFooter,
} from "@/components/ui/sidebar";
import {
  Home,
  HardDrive,
  Settings,
  BarChart3,
  Users,
  LogOut,
  Trophy,
  Server,
  Shield,
  Wifi,
  LineChart,
  Network,
  LayoutDashboard,
  BookOpen,
  HelpCircle,
} from "lucide-react";

export const AppSidebar = () => {
  const location = useLocation();
  
  const isActive = (path: string) => {
    return location.pathname.startsWith(path);
  };

  return (
    <Sidebar className="border-r border-blue-200 bg-gradient-to-b from-blue-700 to-blue-800">
      <SidebarHeader className="p-4 border-b border-blue-600 bg-gradient-to-r from-blue-800 to-blue-900">
        <div className="flex items-center gap-2">
          <Server className="h-6 w-6 text-white" />
          <h2 className="text-xl font-bold text-white">ACS Dashboard</h2>
        </div>
        <p className="text-xs text-blue-300 mt-1">TR-069 Device Management</p>
      </SidebarHeader>
      
      <SidebarContent className="text-blue-100">
        <SidebarGroup>
          <SidebarGroupLabel className="text-blue-300 uppercase text-xs tracking-wider">Main Navigation</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/")} className="hover:bg-blue-600">
                  <Link to="/" className={isActive("/") ? "text-white" : "text-blue-200"}>
                    <LayoutDashboard className={isActive("/") ? "text-white" : "text-blue-300"} />
                    <span>Dashboard</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/devices")} className="hover:bg-blue-600">
                  <Link to="/devices" className={isActive("/devices") ? "text-white" : "text-blue-200"}>
                    <HardDrive className={isActive("/devices") ? "text-white" : "text-blue-300"} />
                    <span>Devices</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/network")} className="hover:bg-blue-600">
                  <Link to="/network" className={isActive("/network") ? "text-white" : "text-blue-200"}>
                    <Network className={isActive("/network") ? "text-white" : "text-blue-300"} />
                    <span>Network</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/wireless")} className="hover:bg-blue-600">
                  <Link to="/wireless" className={isActive("/wireless") ? "text-white" : "text-blue-200"}>
                    <Wifi className={isActive("/wireless") ? "text-white" : "text-blue-300"} />
                    <span>Wireless</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
        
        <SidebarGroup>
          <SidebarGroupLabel className="text-blue-300 uppercase text-xs tracking-wider">Competition</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/competition/analytics")} className="hover:bg-blue-600">
                  <Link to="/competition/analytics" className={isActive("/competition/analytics") ? "text-white" : "text-blue-200"}>
                    <BarChart3 className={isActive("/competition/analytics") ? "text-white" : "text-blue-300"} />
                    <span>Analytics</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/competition/teams")} className="hover:bg-blue-600">
                  <Link to="/competition/teams" className={isActive("/competition/teams") ? "text-white" : "text-blue-200"}>
                    <Trophy className={isActive("/competition/teams") ? "text-white" : "text-blue-300"} />
                    <span>Teams</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/competition/members")} className="hover:bg-blue-600">
                  <Link to="/competition/members" className={isActive("/competition/members") ? "text-white" : "text-blue-200"}>
                    <Users className={isActive("/competition/members") ? "text-white" : "text-blue-300"} />
                    <span>Members</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/competition/statistics")} className="hover:bg-blue-600">
                  <Link to="/competition/statistics" className={isActive("/competition/statistics") ? "text-white" : "text-blue-200"}>
                    <LineChart className={isActive("/competition/statistics") ? "text-white" : "text-blue-300"} />
                    <span>Statistics</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
        
        <SidebarGroup>
          <SidebarGroupLabel className="text-blue-300 uppercase text-xs tracking-wider">System</SidebarGroupLabel>
          <SidebarGroupContent>
            <SidebarMenu>
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/configuration")} className="hover:bg-blue-600">
                  <Link to="/configuration" className={isActive("/configuration") ? "text-white" : "text-blue-200"}>
                    <Settings className={isActive("/configuration") ? "text-white" : "text-blue-300"} />
                    <span>Configuration</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/security")} className="hover:bg-blue-600">
                  <Link to="/security" className={isActive("/security") ? "text-white" : "text-blue-200"}>
                    <Shield className={isActive("/security") ? "text-white" : "text-blue-300"} />
                    <span>Security</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/documentation")} className="hover:bg-blue-600">
                  <Link to="/documentation" className={isActive("/documentation") ? "text-white" : "text-blue-200"}>
                    <BookOpen className={isActive("/documentation") ? "text-white" : "text-blue-300"} />
                    <span>Documentation</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
              
              <SidebarMenuItem>
                <SidebarMenuButton asChild isActive={isActive("/help")} className="hover:bg-blue-600">
                  <Link to="/help" className={isActive("/help") ? "text-white" : "text-blue-200"}>
                    <HelpCircle className={isActive("/help") ? "text-white" : "text-blue-300"} />
                    <span>Help</span>
                  </Link>
                </SidebarMenuButton>
              </SidebarMenuItem>
            </SidebarMenu>
          </SidebarGroupContent>
        </SidebarGroup>
      </SidebarContent>
      
      <SidebarFooter className="border-t border-blue-600 p-4 mt-auto">
        <SidebarMenu>
          <SidebarMenuItem>
            <SidebarMenuButton asChild className="hover:bg-blue-600">
              <Link to="/logout" className="text-blue-200">
                <LogOut className="text-blue-300" />
                <span>Logout</span>
              </Link>
            </SidebarMenuButton>
          </SidebarMenuItem>
        </SidebarMenu>
        <div className="mt-2 text-xs text-blue-400 text-center">
          ACS Dashboard v1.0
        </div>
      </SidebarFooter>
    </Sidebar>
  );
};
