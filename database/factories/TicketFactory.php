<?php

namespace Database\Factories;

use App\Enums\IssueType;
use App\Enums\TicketSeverity;
use App\Enums\TicketStatus;
use App\Models\System;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        /** @var IssueType $issueType */
        $issueType = fake()->randomElement(IssueType::cases());

        return [
            'code' => 'INC-'.fake()->unique()->numberBetween(1000, 99999),
            'system_id' => System::factory(),
            'reporter_external_id' => 'usr_'.strtolower(fake()->bothify('????####')),
            'reporter_name' => fake()->name(),
            'reporter_email' => fake()->safeEmail(),
            'assignee_id' => null,
            'description' => fake()->paragraph(),
            'issue_type' => $issueType,
            'severity' => TicketSeverity::fromIssueType($issueType),
            'status' => TicketStatus::Open,
            'screenshot_path' => null,
            'browser' => fake()->randomElement([
                'Chrome 131 / macOS',
                'Firefox 133 / Windows 11',
                'Safari 18 / iOS 18',
                'Edge 131 / Windows 11',
            ]),
            'ip_address' => fake()->ipv4(),
            'sla_due_at' => now()->addHours(12),
            'resolved_at' => null,
        ];
    }

    /**
     * Indicate that the ticket is open.
     */
    public function open(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::Open,
            'resolved_at' => null,
        ]);
    }

    /**
     * Indicate that the ticket is in progress with an assignee.
     */
    public function inProgress(?User $assignee = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::InProgress,
            'assignee_id' => $assignee?->id ?? User::factory(),
            'resolved_at' => null,
        ]);
    }

    /**
     * Indicate that the ticket is in review with an assignee.
     */
    public function inReview(?User $assignee = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::InReview,
            'assignee_id' => $assignee?->id ?? User::factory(),
            'resolved_at' => null,
        ]);
    }

    /**
     * Indicate that the ticket is resolved.
     */
    public function resolved(?User $assignee = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::Resolved,
            'assignee_id' => $assignee?->id ?? User::factory(),
            'resolved_at' => now()->subHours(2),
        ]);
    }

    /**
     * Indicate that the ticket is closed.
     */
    public function closed(?User $assignee = null): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => TicketStatus::Closed,
            'assignee_id' => $assignee?->id ?? User::factory(),
            'resolved_at' => now()->subDay(),
        ]);
    }

    /**
     * Set a specific severity on the ticket.
     */
    public function withSeverity(TicketSeverity $severity): static
    {
        return $this->state(fn (array $attributes): array => [
            'severity' => $severity,
        ]);
    }
}
