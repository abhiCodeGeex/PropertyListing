import { Component, EventEmitter, Input, Output } from '@angular/core';
import { CommonModule } from '@angular/common';
import { ButtonCloseDirective, ModalModule } from '@coreui/angular';

@Component({
  selector: 'app-confirm-modal',
  standalone: true,
  imports: [CommonModule, ModalModule, ButtonCloseDirective],
  template: `
    <c-modal
      [visible]="visible"
      alignment="center"
      backdrop="static"
      (visibleChange)="handleVisibleChange($event)">
      <c-modal-header>
        <h5 class="modal-title">{{ title }}</h5>
        <button cButtonClose (click)="cancel()" aria-label="Close"></button>
      </c-modal-header>

      <c-modal-body>
        <p>{{ message }}</p>
      </c-modal-body>

      <c-modal-footer>
        <button class="btn btn-secondary" (click)="cancel()">Cancel</button>
        <button class="btn btn-danger" (click)="confirm()">Confirm</button>
      </c-modal-footer>
    </c-modal>
  `
})
export class ConfirmModalComponent {
  @Input() visible = false;
  @Input() title: string = 'Confirm';
  @Input() message: string = 'Are you sure?';

  @Output() visibleChange = new EventEmitter<boolean>();
  @Output() onConfirm = new EventEmitter<void>();

  handleVisibleChange(visible: boolean) {
    this.visible = visible;
    this.visibleChange.emit(visible);
  }

  cancel() {
    this.handleVisibleChange(false);
  }

  confirm() {
    this.onConfirm.emit();
    this.handleVisibleChange(false);
  }
}
