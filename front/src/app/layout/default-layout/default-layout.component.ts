import { Component, computed, DestroyRef, OnInit, inject } from '@angular/core';
import { takeUntilDestroyed } from '@angular/core/rxjs-interop';
import { RouterLink, RouterOutlet } from '@angular/router';
import { NgScrollbar } from 'ngx-scrollbar';
import { interval } from 'rxjs';

import {
  ContainerComponent,
  ShadowOnScrollDirective,
  SidebarBrandComponent,
  SidebarComponent,
  SidebarFooterComponent,
  SidebarHeaderComponent,
  SidebarNavComponent,
  SidebarToggleDirective,
  SidebarTogglerDirective
} from '@coreui/angular';

import { DefaultFooterComponent, DefaultHeaderComponent } from './';
import { buildNavItems } from './_nav';
import { AuthService } from '../../services/auth.service';
import { ChatUnreadService } from '../../modules/chat/chat-unread.service';

function isOverflown(element: HTMLElement) {
  return (
    element.scrollHeight > element.clientHeight ||
    element.scrollWidth > element.clientWidth
  );
}

@Component({
  selector: 'app-dashboard',
  templateUrl: './default-layout.component.html',
  styleUrls: ['./default-layout.component.scss'],
  imports: [
    SidebarComponent,
    SidebarHeaderComponent,
    SidebarBrandComponent,
    SidebarNavComponent,
    SidebarFooterComponent,
    SidebarToggleDirective,
    SidebarTogglerDirective,
    ContainerComponent,
    DefaultFooterComponent,
    DefaultHeaderComponent,
    NgScrollbar,
    RouterOutlet,
    RouterLink,
    ShadowOnScrollDirective
  ]
})
export class DefaultLayoutComponent implements OnInit {
  private readonly destroyRef = inject(DestroyRef);
  private readonly chatUnread = inject(ChatUnreadService);
  readonly navItems = computed(() => buildNavItems(this.authService.roles(), this.chatUnread.count()));

  constructor(private readonly authService: AuthService) {}

  ngOnInit(): void {
    this.chatUnread.refresh();
    interval(8000)
      .pipe(takeUntilDestroyed(this.destroyRef))
      .subscribe(() => this.chatUnread.refresh());
  }
}
