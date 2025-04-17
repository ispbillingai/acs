
import React, { useState, useEffect } from 'react';
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from "@/components/ui/select";
import { toast } from "sonner";
import { Card, CardContent } from "@/components/ui/card";
import { Loader2 } from "lucide-react";

interface WanConfigurationProps {
  deviceId?: string;
}

export function WanConfiguration({ deviceId }: WanConfigurationProps) {
  const [isSubmitting, setIsSubmitting] = useState(false);
  const [connectionType, setConnectionType] = useState("DHCP");
  const [taskStatus, setTaskStatus] = useState<string | null>(null);
  const [currentTaskId, setCurrentTaskId] = useState<string | null>(null);
  const [pollingInterval, setPollingInterval] = useState<number | null>(null);

  // Form state
  const [formState, setFormState] = useState({
    // PPPoE fields
    pppoeUsername: '',
    pppoePassword: '',
    
    // Static IP fields
    staticIp: '',
    subnetMask: '',
    gateway: '',
    dnsServer1: '',
    dnsServer2: ''
  });

  // Handle form input changes
  const handleInputChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const { name, value } = e.target;
    setFormState(prev => ({
      ...prev,
      [name]: value
    }));
  };

  // Handle connection type change
  const handleConnectionTypeChange = (value: string) => {
    setConnectionType(value);
  };

  // Check task status periodically
  useEffect(() => {
    if (currentTaskId && taskStatus === 'pending' || taskStatus === 'in_progress') {
      const interval = window.setInterval(() => {
        checkTaskStatus(currentTaskId);
      }, 3000);
      
      setPollingInterval(interval);
      
      return () => {
        if (pollingInterval) {
          clearInterval(pollingInterval);
        }
      };
    }
  }, [currentTaskId, taskStatus]);

  // Check task status
  const checkTaskStatus = async (taskId: string) => {
    try {
      const response = await fetch(`/backend/api/device_configure.php`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: new URLSearchParams({
          'action': 'check_task_status',
          'task_id': taskId
        })
      });
      
      const data = await response.json();
      
      if (data.success) {
        setTaskStatus(data.status);
        
        if (data.status === 'completed') {
          toast.success("WAN configuration completed successfully");
          
          // Clear polling
          if (pollingInterval) {
            clearInterval(pollingInterval);
            setPollingInterval(null);
          }
        } else if (data.status === 'failed') {
          toast.error(`WAN configuration failed: ${data.message}`);
          
          // Clear polling
          if (pollingInterval) {
            clearInterval(pollingInterval);
            setPollingInterval(null);
          }
        }
      }
    } catch (error) {
      console.error("Error checking task status:", error);
    }
  };

  // Submit WAN configuration
  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    
    if (!deviceId) {
      toast.error("Device ID is required");
      return;
    }
    
    setIsSubmitting(true);
    
    // Prepare form data based on connection type
    const formData = new URLSearchParams();
    formData.append('action', 'wan');
    formData.append('device_id', deviceId);
    formData.append('connection_type', connectionType);
    
    if (connectionType === 'PPPoE') {
      formData.append('pppoe_username', formState.pppoeUsername);
      formData.append('pppoe_password', formState.pppoePassword);
    } else if (connectionType === 'Static') {
      formData.append('ip_address', formState.staticIp);
      formData.append('subnet_mask', formState.subnetMask);
      formData.append('gateway', formState.gateway);
      formData.append('dns_server1', formState.dnsServer1);
      formData.append('dns_server2', formState.dnsServer2);
    }
    
    try {
      const response = await fetch('/backend/api/device_configure.php', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/x-www-form-urlencoded',
        },
        body: formData
      });
      
      const data = await response.json();
      
      if (data.success) {
        toast.success("WAN configuration request sent");
        setCurrentTaskId(data.task_id);
        setTaskStatus('pending');
      } else {
        toast.error(`Failed to update WAN configuration: ${data.message}`);
      }
    } catch (error) {
      console.error("Error updating WAN configuration:", error);
      toast.error("An error occurred while updating WAN configuration");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <Card>
      <CardContent className="pt-6">
        <form onSubmit={handleSubmit} className="space-y-4">
          <div className="space-y-2">
            <Label htmlFor="connectionType">Connection Type</Label>
            <Select value={connectionType} onValueChange={handleConnectionTypeChange}>
              <SelectTrigger className="w-full">
                <SelectValue placeholder="Select connection type" />
              </SelectTrigger>
              <SelectContent>
                <SelectItem value="DHCP">DHCP (Automatic)</SelectItem>
                <SelectItem value="PPPoE">PPPoE</SelectItem>
                <SelectItem value="Static">Static IP</SelectItem>
              </SelectContent>
            </Select>
          </div>
          
          {/* PPPoE Fields - Always visible but disabled when not selected */}
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="pppoeUsername">PPPoE Username</Label>
              <Input 
                id="pppoeUsername"
                name="pppoeUsername"
                value={formState.pppoeUsername}
                onChange={handleInputChange}
                placeholder="Enter username provided by ISP"
                disabled={connectionType !== 'PPPoE'}
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="pppoePassword">PPPoE Password</Label>
              <Input 
                id="pppoePassword"
                name="pppoePassword"
                type="password"
                value={formState.pppoePassword}
                onChange={handleInputChange}
                placeholder="Enter password provided by ISP"
                disabled={connectionType !== 'PPPoE'}
              />
            </div>
          </div>
          
          {/* Static IP Fields - Always visible but disabled when not selected */}
          <div className="space-y-4">
            <div className="space-y-2">
              <Label htmlFor="staticIp">IP Address</Label>
              <Input 
                id="staticIp"
                name="staticIp"
                value={formState.staticIp}
                onChange={handleInputChange}
                placeholder="e.g., 192.168.1.100"
                disabled={connectionType !== 'Static'}
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="subnetMask">Subnet Mask</Label>
              <Input 
                id="subnetMask"
                name="subnetMask"
                value={formState.subnetMask}
                onChange={handleInputChange}
                placeholder="e.g., 255.255.255.0"
                disabled={connectionType !== 'Static'}
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="gateway">Default Gateway</Label>
              <Input 
                id="gateway"
                name="gateway"
                value={formState.gateway}
                onChange={handleInputChange}
                placeholder="e.g., 192.168.1.1"
                disabled={connectionType !== 'Static'}
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="dnsServer1">Primary DNS Server</Label>
              <Input 
                id="dnsServer1"
                name="dnsServer1"
                value={formState.dnsServer1}
                onChange={handleInputChange}
                placeholder="e.g., 8.8.8.8"
                disabled={connectionType !== 'Static'}
              />
            </div>
            
            <div className="space-y-2">
              <Label htmlFor="dnsServer2">Secondary DNS Server</Label>
              <Input 
                id="dnsServer2"
                name="dnsServer2"
                value={formState.dnsServer2}
                onChange={handleInputChange}
                placeholder="e.g., 8.8.4.4"
                disabled={connectionType !== 'Static'}
              />
            </div>
          </div>
          
          {taskStatus === 'pending' || taskStatus === 'in_progress' ? (
            <div className="flex items-center space-x-2 rounded-md border border-yellow-200 bg-yellow-50 p-3">
              <Loader2 className="h-5 w-5 animate-spin text-yellow-600" />
              <p className="text-sm text-yellow-700">
                {taskStatus === 'pending' ? 'Waiting to apply WAN configuration...' : 'Applying WAN configuration...'}
              </p>
            </div>
          ) : (
            <Button type="submit" disabled={isSubmitting} className="w-full">
              {isSubmitting ? "Updating..." : "Update WAN Configuration"}
            </Button>
          )}
          
          {taskStatus === 'failed' && (
            <div className="rounded-md border border-red-200 bg-red-50 p-3">
              <p className="text-sm text-red-700">
                WAN configuration failed. Please check device logs or try again.
              </p>
            </div>
          )}
          
          {taskStatus === 'completed' && (
            <div className="rounded-md border border-green-200 bg-green-50 p-3">
              <p className="text-sm text-green-700">
                WAN configuration successfully applied.
              </p>
            </div>
          )}
        </form>
      </CardContent>
    </Card>
  );
}
