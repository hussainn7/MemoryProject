<?php

namespace App\Entity;

use App\EntityInterface\HistoryInterface;
use App\Entity\PaymentTransaction;
use App\Repository\UserRepository;
use App\Traits\Entity\EntityDatesTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Knp\DoctrineBehaviors\Model\SoftDeletable\SoftDeletableTrait;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use OpenApi\Attributes as OA;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\HasLifecycleCallbacks]
#[ORM\Table(name: '`user`')]
#[ORM\Index(columns: ['uuid'], name: 'i_uuid')]
class User implements UserInterface, PasswordAuthenticatedUserInterface, HistoryInterface
{

     use EntityDatesTrait;
     use SoftDeletableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[ORM\JoinColumn(nullable: true)]
    private ?string $firstName = null;

    #[ORM\Column(type: 'string', nullable: true)]
    #[ORM\JoinColumn(nullable: true)]
    private ?string $lastName = null;

    #[OA\Property(type: 'string', maxLength: 255)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    #[ORM\Column(type: 'string', unique: true)]
    private ?string $username = null;

    #[ORM\Column(length: 180, unique: true)]
    private ?string $email = null;



    #[ORM\ManyToOne(targetEntity: Role::class, inversedBy: 'users')]
    private ?Role $role = null;

    #[ORM\Column(type: 'string')]
    private ?string $password = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    #[OA\Property(description: 'UUID of the user.')]
    #[ORM\Column(type: 'string', unique: true, nullable: false)]
    private ?string $uuid = null;

    /**
     * @var Collection<int, QrCode>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: QrCode::class)]
    private Collection $qrCodes;

    /**
     * @var Collection<int, QrCode>
     */
    #[ORM\OneToMany(mappedBy: 'client', targetEntity: QrCode::class)]
    private Collection $qrCodeClient;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, options: ['default' => '0.00'])]
    private string $balance = '0.00';

    /**
     * @var Collection<int, PaymentTransaction>
     */
    #[ORM\OneToMany(mappedBy: 'user', targetEntity: PaymentTransaction::class, cascade: ['persist'])]
    private Collection $paymentTransactions;


    public function __construct()
    {
        if (null === $this->uuid || '' == $this->uuid) {
            $this->uuid = Uuid::uuid4();
        }
        $this->qrCodes = new ArrayCollection();
        $this->qrCodeClient = new ArrayCollection();
        $this->paymentTransactions = new ArrayCollection();
    }
    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
//    public function getUserIdentifier(): string
//    {
//        return (string) $this->email;
//    }

    public function getFirstname(): ?string
    {
        return $this->firstName;
    }

    public function setFirstname($firstName): self
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastName;
    }

    public function setLastname($lastName): self
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName.' '.$this->lastName;
    }

    // UserInterface since 5.3
    public function getUserIdentifier(): string
    {
        return $this->username;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(string $username): User
    {
        $this->username = $username;

        return $this;
    }

//    /**
//     * @see UserInterface
//     */
//    public function getRoles(): array
//    {
//        $roles = $this->roles;
//        // guarantee every user at least has ROLE_USER
//        $roles[] = 'ROLE_USER';
//
//        return array_unique($roles);
//    }
//
//    public function setRoles(array $roles): static
//    {
//        $this->roles = $roles;
//
//        return $this;
//    }


    public function getRoles(): array
    {
        if (null !== $this->role) {
            $roleName = $this->getRole()->getName();

            return [$roleName];
        }

        return [];
    }

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): self
    {
        $this->role = $role;

        return $this;
    }
    
    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        // $this->plainPassword = null;
    }

    public function getUuid(): ?string
    {
        return $this->uuid;
    }

    public function setUuid($uuid): self
    {
        $this->uuid = $uuid;

        return $this;
    }

    public function getHistorySlug(): string
    {
        return $this->firstName.' '.$this->lastName.' (ID: '.$this->id.')';
    }

    /**
     * @return Collection<int, QrCode>
     */
    public function getQrCodes(): Collection
    {
        return $this->qrCodes;
    }

    public function addQrCode(QrCode $qrCode): static
    {
        if (!$this->qrCodes->contains($qrCode)) {
            $this->qrCodes->add($qrCode);
            $qrCode->setUser($this);
        }

        return $this;
    }

    public function removeQrCode(QrCode $qrCode): static
    {
        if ($this->qrCodes->removeElement($qrCode)) {
            // set the owning side to null (unless already changed)
            if ($qrCode->getUser() === $this) {
                $qrCode->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, QrCode>
     */
    public function getQrCodeClient(): Collection
    {
        return $this->qrCodeClient;
    }

    public function addQrCodeClient(QrCode $qrCodeClient): static
    {
        if (!$this->qrCodeClient->contains($qrCodeClient)) {
            $this->qrCodeClient->add($qrCodeClient);
            $qrCodeClient->setClient($this);
        }

        return $this;
    }

    public function removeQrCodeClient(QrCode $qrCodeClient): static
    {
        if ($this->qrCodeClient->removeElement($qrCodeClient)) {
            // set the owning side to null (unless already changed)
            if ($qrCodeClient->getClient() === $this) {
                $qrCodeClient->setClient(null);
            }
        }

        return $this;
    }

    public function getBalance(): float
    {
        return (float) $this->balance;
    }

    public function setBalance(float $balance): self
    {
        $this->balance = number_format($balance, 2, '.', '');

        return $this;
    }

    public function increaseBalance(float $amount): self
    {
        $this->setBalance($this->getBalance() + $amount);

        return $this;
    }

    public function decreaseBalance(float $amount): self
    {
        $newBalance = $this->getBalance() - $amount;
        $this->setBalance($newBalance < 0 ? 0.0 : $newBalance);

        return $this;
    }

    /**
     * @return Collection<int, PaymentTransaction>
     */
    public function getPaymentTransactions(): Collection
    {
        return $this->paymentTransactions;
    }

    public function addPaymentTransaction(PaymentTransaction $paymentTransaction): self
    {
        if (!$this->paymentTransactions->contains($paymentTransaction)) {
            $this->paymentTransactions->add($paymentTransaction);
            $paymentTransaction->setUser($this);
        }

        return $this;
    }

    public function removePaymentTransaction(PaymentTransaction $paymentTransaction): self
    {
        if ($this->paymentTransactions->removeElement($paymentTransaction)) {
            if ($paymentTransaction->getUser() === $this) {
                $paymentTransaction->setUser(null);
            }
        }

        return $this;
    }
}
